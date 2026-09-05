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
| Providers | filimo, sheyda, filmnet — JSON files in `app/providers/`, no login needed by the server |
| TLS | Virtualmin-managed certificate (`ssl.combined`) |
| Unix user | `serial-reminder` (no sudo) |
| Cron | hourly episode check + nightly backup, in `serial-reminder`'s crontab |

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
| `episodes` | every episode, whether from a watch report (`source = watch`) or the hourly check (`source = catalog`) |
| `provider_accounts` | which mobile number each site is logged in with |
| `schema_migrations` | which `.sql` files have run |

**Migrations run once each, in filename order**, tracked in `schema_migrations`.
They do not have to be idempotent — `002` and later use plain `ALTER TABLE`.
Add a new one as `005_*.sql`; `bin/sr.php migrate` picks it up.

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
| Every show suddenly shows new episodes | A provider's `skipWhen` no longer filters "coming soon" entries. |
| Extension reaches the server but never posts a watch | The tracker is not in that tab. Check `grep "POST /api/watch" logs/access_log`. Reload the extension; reload the page if it persists. |
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
