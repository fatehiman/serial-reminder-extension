# Deployment — serial-reminder

Technical reference for the live installation on **ger1**
(`hetzner-webserver`, `162.55.167.140`), serving
**https://serial-reminder.kimiasoft.com/**.

Deployed: 2026-09-05. Passwords and keys are in `CREDENTIALS.md` (not in git).

---

## 1. What is running

| | |
|---|---|
| Server | ger1 — Ubuntu 24.04, Virtualmin, ~103 virtual servers |
| Web | Apache 2.4 with a **per-domain PHP-FPM pool** (suexec, own Unix user) |
| PHP | 8.4.25, pool socket `/run/php/17886125251977616.sock` |
| PHP modules added for this app | **`php8.4-sqlite3`** (installed 2026-09-05) |
| Database | SQLite, one file, WAL mode |
| Providers | filimo, sheyda, filmnet, namava — JSON files in `app/providers/`, no login needed by the server |
| Relay | `sr-relay.sitechee.ir` on **waybill**, only for namava — see section 13 |
| TLS | Virtualmin-managed certificate (`ssl.combined`) |
| Unix user | `serial-reminder` (no sudo) |
| Cron | hourly episode check + nightly backup, in `serial-reminder`'s crontab |
| Telegram | new episodes are announced through `sms.kimiasoft.com` — see section 14 |

> `php8.4-sqlite3` was not installed on ger1 before this project. Installing it
> restarted the shared `php8.4-fpm` service, a sub-second interruption for every
> PHP 8.4 site on the box. Keep that in mind before adding more PHP modules.

## 2. Directory layout

Virtualmin gives the domain a home at `/mnt/ger_hd1/www/serial-reminder`.
Only `public_html` is web-reachable.

```
/mnt/ger_hd1/www/serial-reminder/
├── public_html/            ← docroot (Apache)
│   ├── index.php               single front controller
│   ├── .htaccess               rewrites, security headers
│   └── assets/                 app.css, dashboard.js, icon.svg
├── app/                    ← NOT web-reachable
│   ├── bootstrap.php
│   ├── config.php              live settings, chmod 600, not in git
│   ├── config.example.php
│   ├── src/                    PHP classes
│   ├── views/                  login, dashboard, settings
│   ├── providers/              one JSON file per movie site
│   ├── migrations/             *.sql, applied once each, in name order
│   └── bin/sr.php              command line tool
├── data/
│   └── serial-reminder.sqlite  the database (chmod 660, dir 750)
├── backups/                    sr-1.sqlite … sr-7.sqlite (one per weekday)
└── logs/                       check.log, backup.log, access_log, error_log
```

`public/index.php` looks for `../app/bootstrap.php` first and falls back to
`../bootstrap.php`, so the same file works on the server and in a local checkout.

### What the database holds

| Table | What is in it |
|---|---|
| `users` | one row — username, password hash, API key |
| `sessions`, `login_tickets` | dashboard logins; tickets are single use, 60 seconds |
| `serials` | one row per followed show. `confirmed = 0` means "opened but never really watched" — hidden, and swept after `candidate_days` (90). |
| `episodes` | every episode, whether from a watch report (`source = watch`) or the hourly check (`source = catalog`). `notified_at` is when the Telegram message about it went out — NULL means not yet, and it is what stops the same episode being announced twice. |
| `provider_accounts` | which mobile number each site is logged in with |
| `schema_migrations` | which `.sql` files have run |

**Migrations run once each, in filename order**, tracked in `schema_migrations`.
They do not have to be idempotent — `002` and later use plain `ALTER TABLE`.
Add a new one as `006_*.sql`; `bin/sr.php migrate` picks it up.

## 3. Connecting

**Whitelist your IP first, every single time.** A Hetzner *cloud* firewall sits
in front of the VM; a non-whitelisted IP just times out.

```bash
curl -s "https://mon.peppasoft.com/addip?_type=s"
sleep 5
ssh Ger1-root "hostname"
```

- `Ger1-root` → root, key `~/.ssh/id_ed25519_ger1_root`. There is **no root
  password**.
- The site user has no sudo. Anything privileged goes through `Ger1-root`.
- **fail2ban bans for one week after 5 failed logins.** Never retry in a loop.

## 4. First-time setup (already done — for reference or a rebuild)

```bash
# 1. the virtual server (Virtualmin owns the user, vhost, DNS, TLS and FPM pool)
virtualmin create-domain --domain serial-reminder.kimiasoft.com \
    --pass '<password>' --unix --dir --webmin --web --ssl --dns

# 2. PHP needs SQLite
apt-get install -y php8.4-sqlite3

# 3. directories
D=/mnt/ger_hd1/www/serial-reminder
mkdir -p $D/app $D/data $D/backups $D/logs

# 4. code (see section 5)

# 5. config, outside the docroot, readable only by the site user
cp $D/app/config.example.php $D/app/config.php && $EDITOR $D/app/config.php
chown serial-reminder:serial-reminder $D/app/config.php
chmod 600 $D/app/config.php

# 6. database and the first account
sudo -u serial-reminder php8.4 $D/app/bin/sr.php migrate
sudo -u serial-reminder php8.4 $D/app/bin/sr.php user:add ioan
#   -> prints the password and the API key. Save both in CREDENTIALS.md.

# 7. cron (see section 7)
```

## 5. Deploying an update

From the workstation, in the repo root:

```bash
curl -s "https://mon.peppasoft.com/addip?_type=s" >/dev/null && sleep 3

T=$(mktemp -d)
mkdir -p "$T/pkg/app" "$T/pkg/public_html"
cp -r server/src server/views server/providers server/migrations \
      server/bin server/bootstrap.php server/config.example.php "$T/pkg/app/"
cp -r server/public/. "$T/pkg/public_html/"
rm -f "$T/pkg/public_html/router.php"          # dev-only helper
(cd "$T/pkg" && tar czf ../deploy.tgz .)

scp "$T/deploy.tgz" Ger1-root:/tmp/sr-deploy.tgz

ssh Ger1-root '
  set -e
  D=/mnt/ger_hd1/www/serial-reminder
  # back up before replacing anything
  sqlite3 $D/data/serial-reminder.sqlite ".backup $D/backups/pre-deploy.sqlite"
  tar czf $D/backups/app-$(date +%F-%H%M).tgz -C $D app public_html
  # config.php is not in the tarball, so it survives
  tar xzf /tmp/sr-deploy.tgz -C $D
  chown -R serial-reminder:serial-reminder $D/app $D/public_html
  chmod 600 $D/app/config.php
  sudo -u serial-reminder php8.4 $D/app/bin/sr.php migrate
  rm -f /tmp/sr-deploy.tgz
'
```

Then **flush the opcache** (see section 6) and verify (section 8).

The tarball never contains `config.php`, `data/` or `.git`, so a deploy cannot
overwrite the live settings or the database.

> Extracting **over** the old directory leaves deleted files behind. If a release
> removes a file, delete it on the server by hand, or extract into a new
> directory and swap.

### Updating the extension

The extension is **not** deployed — it is loaded unpacked from the `extension/`
folder in the checkout. After a `git pull` that touched `extension/`, reload it
at `chrome://extensions` (the ↻ icon). Only that folder matters; the version in
`manifest.json` is cosmetic.

The background worker now re-injects the tracker into tabs that are already
open, so reloading the extension is enough — no need to reload each page.

### Deploying only a provider script

This is the common case — adding support for a new movie site:

```bash
scp server/providers/<name>.json Ger1-root:/tmp/
ssh Ger1-root 'D=/mnt/ger_hd1/www/serial-reminder
  mv /tmp/<name>.json $D/app/providers/
  chown serial-reminder:serial-reminder $D/app/providers/<name>.json
  sudo -u serial-reminder php8.4 $D/app/bin/sr.php providers'
```

No PHP changed, so no opcache flush is needed. Browsers pick the file up within
6 hours, or immediately from *extension Settings → Reload site scripts*.

## 6. The opcache trap on ger1

Each domain has its own FPM pool, and the pools cache PHP bytecode **without
checking file timestamps**. After editing a `.php` on the server the old code
often keeps running. A CLI `php` run always reads the file, so **testing from
the CLI proves nothing about the web side.**

To flush this domain's pool:

```bash
ssh Ger1-root 'echo "<?php opcache_reset(); echo \"flushed\";" \
  > /mnt/ger_hd1/www/serial-reminder/public_html/flush.php
  chown serial-reminder:serial-reminder /mnt/ger_hd1/www/serial-reminder/public_html/flush.php'

curl -s https://serial-reminder.kimiasoft.com/flush.php   # once

ssh Ger1-root 'rm -f /mnt/ger_hd1/www/serial-reminder/public_html/flush.php'
```

`opcache_reset()` is **per pool**. Flushing another domain does nothing here.

## 7. Cron

Installed in the `serial-reminder` user's crontab
(`crontab -u serial-reminder -l`):

```cron
# look for new episodes every hour
17 * * * * /usr/bin/php8.4 /mnt/ger_hd1/www/serial-reminder/app/bin/sr.php check --quiet \
           >> /mnt/ger_hd1/www/serial-reminder/logs/check.log 2>&1

# nightly SQLite backup, one file per weekday (7 day rotation)
23 4 * * * /usr/bin/sqlite3 /mnt/ger_hd1/www/serial-reminder/data/serial-reminder.sqlite \
           ".backup /mnt/ger_hd1/www/serial-reminder/backups/sr-$(date +\%u).sqlite" \
           >> /mnt/ger_hd1/www/serial-reminder/logs/backup.log 2>&1
```

`check` skips any show checked in the last 55 minutes; `--force` ignores that.
A `%` in a crontab must be written `\%`.

Run it by hand:

```bash
sudo -u serial-reminder php8.4 /mnt/ger_hd1/www/serial-reminder/app/bin/sr.php check --force
```

## 8. Verifying a deploy

A deploy is not finished until these all pass:

```bash
curl -s https://serial-reminder.kimiasoft.com/health                  # -> ok
curl -sI https://serial-reminder.kimiasoft.com/login | head -1        # -> 200
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' \
     https://serial-reminder.kimiasoft.com/                           # -> 302 /login

KEY=<api key>
curl -s -H "Authorization: Bearer $KEY" \
     https://serial-reminder.kimiasoft.com/api/ping                   # -> {"ok":true,...}
curl -s -H "Authorization: Bearer $KEY" \
     https://serial-reminder.kimiasoft.com/api/providers | head -c 80

# the secrets must NOT be reachable
curl -s -o /dev/null -w '%{http_code}\n' \
     https://serial-reminder.kimiasoft.com/config.php                 # -> 404
```

Retry the health check ~5 times a second apart — an Apache reload is not
instant.

## 9. Command line tool

Always run it as the site user, so new files are not left owned by root:

```bash
D=/mnt/ger_hd1/www/serial-reminder
sudo -u serial-reminder php8.4 $D/app/bin/sr.php <command>
```

| Command | What it does |
|---|---|
| `migrate` | apply any new `.sql` in `migrations/` |
| `user:add <name> [pw]` | create an account, print the password and API key |
| `user:list` | accounts and their API keys |
| `user:token <name>` | issue a new API key (invalidates the old one) |
| `user:password <name> <pw>` | set a password |
| `providers` | list provider scripts, check they parse |
| `check [--serial=N] [--force] [--quiet]` | look for new episodes |
| `serials [username]` | what the server thinks you are watching |
| `notify:test ["message"]` | send one Telegram message, to prove `notify.url` works |

## 10. Backup and restore

Nightly backups rotate weekly in `backups/sr-1.sqlite` … `sr-7.sqlite`
(`1` = Monday). Take one by hand before anything risky:

```bash
ssh Ger1-root 'D=/mnt/ger_hd1/www/serial-reminder
  sqlite3 $D/data/serial-reminder.sqlite ".backup $D/backups/manual-$(date +%F-%H%M).sqlite"'
```

Restore:

```bash
ssh Ger1-root 'D=/mnt/ger_hd1/www/serial-reminder
  cp $D/backups/sr-3.sqlite $D/data/serial-reminder.sqlite
  rm -f $D/data/serial-reminder.sqlite-wal $D/data/serial-reminder.sqlite-shm
  chown serial-reminder:serial-reminder $D/data/serial-reminder.sqlite
  chmod 660 $D/data/serial-reminder.sqlite'
```

Never copy a live WAL database with `cp` — use `.backup`, which is consistent.

## 11. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| SSH to ger1 **times out** | IP whitelist expired. Re-run the `addip` call. Not a ban. |
| Edited PHP has no effect on the web | Per-pool opcache. Flush it (section 6). |
| 500 on every page | `config.php` missing or unreadable. Check it exists and is `chmod 600`, owned by `serial-reminder`. |
| `could not find driver` | `php8.4-sqlite3` missing, or FPM not reloaded after installing it. |
| `attempt to write a readonly database` | The `data/` directory or the `.sqlite` file is not writable by `serial-reminder`. Files left owned by `root` after a deploy are the usual cause — `chown -R serial-reminder:serial-reminder`. |
| Dashboard fine, extension says 401 | Wrong API key, or Apache stripped the `Authorization` header — the `.htaccess` rewrite that re-adds it must be present. |
| Checker says "request failed or returned no JSON" | The site changed its API, or it is blocking the server. Test the URL by hand from ger1 with `curl`. |
| Checker says "needs the 'iran' relay, which config.php does not define" | `relays` is missing from `config.php`. Section 13. |
| Namava alone finds nothing, no error | The relay is down or its key changed, or namava's own API moved. Section 13 has the two commands that tell those apart. |
| No Telegram message for an episode that is clearly new | Was it the show's **first** check? Those are silent on purpose. Otherwise run `sr.php check --serial=N --force` and read the line it prints; `NOTIFY FAILED` gives the reason. `SELECT number, notified_at FROM episodes WHERE serial_id = N` shows what was already announced. |
| A message is accepted but never arrives | Almost certainly an emoji. The gateway throws away any message that **starts** with one, and still answers 200 with an id. See "What the gateway cannot carry" in section 14. |
| The same episode was announced twice | Should be impossible — `notified_at` is stamped only after a message really went out. If it happens, two checks ran at the same moment; look for a second cron entry with `crontab -u serial-reminder -l`. |
| Every show suddenly shows new episodes | A provider's `skipWhen` no longer filters "coming soon" entries. |
| Extension reaches the server but never posts a watch | The tracker is not in that tab. Check `grep "POST /api/watch" logs/access_log`. Reload the extension; reload the page if it persists. |
| An episode you watched is still "unseen", play time `00:00` | The server was never told. Nothing was posted for it — check `grep "POST /api/watch" logs/access_log` around that evening. The usual cause is the extension not running on the computer you watched on. |
| Chrome dropped the unpacked extension after a restart | An unpacked extension is only remembered by its folder path. Chrome silently removes it when that folder is gone at startup — a second drive that mounts late, a network or removable drive, a renamed folder — and enterprise policy (`ExtensionInstallBlocklist`) removes it too. Keep `extension/` on the system drive under the user's own home, load it again, then restart Chrome once and confirm it survived. `chrome://policy` shows a policy if one is at fault. |
| A show never appears on the dashboard | It needs 20 minutes of real playing, or a finished episode. Look for it with `SELECT * FROM serials WHERE confirmed = 0`. |
| Wrong season on one provider | Something in that provider reads `document.title`. In a single page app the title lags the URL — take the value from the API. |
| Apache logs | `tail -20 /var/log/apache2/error.log`, and this domain's own logs in `$D/logs/` |

### Rules for this box

1. **Never** let a recursive command descend into
   `/mnt/ger_hd1/www/kimiasoft/public_html` — it is a 1.3 TB WebDAV mount and
   has taken docroots offline before. Exclude it explicitly.
2. ger1 has ~3.8 GB RAM and ~100 tenants. Do not add a daemon here.
3. Reload, never restart, shared services. Touch only this domain.
4. `ssh.socket` is disabled on purpose. Do not re-enable it.

## 12. Removing the installation

```bash
ssh Ger1-root 'crontab -u serial-reminder -r
  virtualmin delete-domain --domain serial-reminder.kimiasoft.com'
```

Take a copy of `data/serial-reminder.sqlite` first — it is the only thing that
cannot be rebuilt from git.

## 13. The Iran relay (namava only)

Namava answers only Iranian IP addresses. It does not say so: from ger1 it
returns **HTTP 200** and `succeeded: true` with an **empty** result, which the
checker would read as "this show has no new episodes". So namava's catalog is
fetched through a relay on **waybill** (`130.185.76.10`), which is in Iran.

```
ger1 cron ──HTTPS──► sr-relay.sitechee.ir ──HTTPS──► www.namava.ir/api/...
  (Germany)          (waybill, Iran)                 (answers Iranian IPs)
             X-Relay-Key: <key from config.php>
```

Everything else about namava — the tracker and the mobile number — runs in your
own browser, which is already in Iran, and never touches the relay.

### What is installed on waybill

| | |
|---|---|
| Code | `/var/www/sr-relay/index.php` — the repo's `server/relay/index.php` |
| Key | `/etc/serial-reminder-relay.key`, `chmod 400`, owned by `www-data` |
| vhost | `/etc/apache2/sites-available/sr-relay.conf` — the repo's `server/relay/apache-vhost.conf.example`, PHP via the default `php8.4-fpm.sock` pool |
| Name | `sr-relay.sitechee.ir` |

`*.sitechee.ir` already resolves to waybill through Cloudflare and the existing
`/etc/ssl/certs/sitechee.ir.crt` is a `*.sitechee.ir` wildcard, so this vhost
needed **no DNS change and no new certificate**.

The relay is deliberately dull: GET only, `https` only, and only to the hosts
and path prefixes in the `ALLOW` constant at the top of `index.php`
(`www.namava.ir` + `/api/`). It cannot be used as a general proxy, it follows no
redirects, and it forwards no cookies.

### The ger1 side

`config.php` on ger1 carries the address and the key. This is why they are not
in the provider file: `/api/providers` hands provider files to every browser.

```php
'relays' => [
    'iran' => [
        'url'     => 'https://sr-relay.sitechee.ir/?u={url}',
        'headers' => ['X-Relay-Key' => '<the key>'],
    ],
],
```

A catalog that asks for a relay `config.php` does not define fails loudly
instead of silently fetching direct and getting the empty answer.

### Updating or checking it

```bash
# the relay refuses without the key, and refuses any host but namava
curl -s -o /dev/null -w '%{http_code}\n' 'https://sr-relay.sitechee.ir/?u=x'   # -> 403

KEY=<from CREDENTIALS.md>
TARGET=https%3A%2F%2Fwww.namava.ir%2Fapi%2Fv2.0%2Fmedias%2F149441%2Fsingle-series
curl -s -H "X-Relay-Key: $KEY" "https://sr-relay.sitechee.ir/?u=$TARGET" | head -c 120
#   -> {"succeeded":true,"result":{...

# ask namava directly from waybill, to tell "relay broken" from "namava changed"
ssh waybill 'curl -s https://www.namava.ir/api/v2.0/medias/149441/single-series | head -c 120'
```

To deploy a new version of the relay:

```bash
scp server/relay/index.php waybill:/tmp/sr-relay-index.php
ssh waybill 'mv /tmp/sr-relay-index.php /var/www/sr-relay/index.php
  chown www-data:www-data /var/www/sr-relay/index.php
  chmod 644 /var/www/sr-relay/index.php'
```

To rotate the key, write a new one to `/etc/serial-reminder-relay.key` on
waybill **and** to `relays.iran.headers` in ger1's `config.php`. Between those
two edits the hourly check reports a namava error; nothing else is affected.

## 14. Telegram, when an episode is published

The hourly check sends a message about every newly published episode, once.

```
cron ──► catalog of each show ──► new episode? ──► GET sms.kimiasoft.com/sendMsg
                                                    ?p=<account>&c=t&r=monitoring&m=<text>
```

`config.php` on ger1 holds the address, because it carries the gateway account
id. `{msg}` is replaced with the url-encoded message. The gateway has no
newline, so `line_break` is what it wants instead — `_C` here.

```php
'notify' => [
    'url'          => 'https://sms.kimiasoft.com/sendMsg?p=<id>&c=t&r=monitoring&m={msg}',
    'line_break'   => '_C',
    'max_episodes' => 6,
],
```

An empty `url` switches the whole thing off, silently and on purpose.

### The "only once" rule

`episodes.notified_at` is the record. It is stamped **only after the gateway
answered 2xx**, so a failed send is retried on the next hourly run, and a
message that really went out is never repeated.

Two kinds of episode are deliberately never announced, and are stamped without
sending:

- anything found on a show's **first ever check** (`last_checked_at IS NULL`).
  That run imports the entire back catalogue.
- episodes already marked watched — which is how the back catalogue of a show
  you joined at episode 20 is stored.

Migration `005_notify.sql` stamps every episode that existed when it ran, for
the same reason.

### What the gateway cannot carry: emoji

Measured against the live gateway, one message at a time, on 2026-09-05:

| In the message | Result |
|---|---|
| Persian text, even as the first character | arrives |
| em dash `—`, colon, four or more lines | arrives |
| a link | arrives |
| an emoji in the middle | arrives, **without** the emoji |
| an emoji as the **first** character | **never arrives** |

The last row is the dangerous one. The request is accepted, HTTP 200, with a
normal `telegramResult=<id>` — and nothing is delivered. Nothing in the answer
says the message was lost, so `notified_at` gets stamped and the episode is
never announced again.

`Notify::stripAstral()` therefore removes every character above U+FFFF before
sending, and no message template contains an emoji. Show and episode titles
come from the movie sites, so one of them may bring an emoji along one day;
stripping means that message still goes out.

### Testing it

```bash
D=/mnt/ger_hd1/www/serial-reminder
sudo -u serial-reminder php8.4 $D/app/bin/sr.php notify:test
sudo -u serial-reminder php8.4 $D/app/bin/sr.php notify:test "one line_Cnext line"
```

A successful gateway reply starts with `telegramResult=<id>`. The tool only
looks at the HTTP status: a 2xx counts as sent, because retrying every hour
against a gateway that already delivered would be worse than one lost message.
