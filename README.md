# Serial Reminder

Keeps track of the TV shows you are watching, remembers **which episode you saw
last**, and tells you **which shows have a new episode waiting**.

You never type anything. You just watch. The Chrome extension notices what is
playing, and the dashboard shows the whole list with the new ones highlighted.
Click a show and it opens exactly the episode you should watch next.

Everything is stored on your own server, so it works the same on every computer
and every Chrome profile you use.

```
   Chrome + extension                     your server
  ┌────────────────────┐                ┌──────────────────────────┐
  │ tracker on the     │  POST /watch   │  PHP 8.4 + SQLite        │
  │ movie site         │ ─────────────► │  ├ API                   │
  │                    │                │  ├ dashboard             │
  │ downloads the site │  GET /providers│  ├ provider scripts      │
  │ scripts from here  │ ◄───────────── │  └ hourly episode check  │
  └────────────────────┘                └──────────────────────────┘
                                                     │
                                          asks the movie site
                                          "any new episodes?"
```

---

## What it does

- **Remembers your place.** While an episode plays, the extension counts the real
  playing time and the player position and sends it to the server.
- **Decides when an episode is finished.** An episode counts as watched when
  **both** are true: the player got past **70%** of it, *and* you really played
  at least a quarter of its length (never less than 5 minutes). 70%, not more,
  because people skip ads, the recap and the credits. The second half matters
  because the position lies — sites resume where you left off, so opening an
  episode can show 90% after two seconds. Playing 70% of the length counts on
  its own, and if the length is unknown, 20 minutes of playing is the whole test.
- **Only follows shows you really watch.** Opening a page and sampling a minute
  does **not** add it to your list. A show joins the list when you finish an
  episode of it, or simply play **20 minutes** of one. Until then the progress
  is still counted quietly, so 15 minutes today and 15 tomorrow still add up.
  A show that never reached that point, and that you never came back to, is
  forgotten after 90 days. Shows on your list are only ever removed by you.

All of these numbers live in `config.php`.
- **Finds new episodes by itself.** Every hour the server asks each show's site
  which episodes exist and marks the ones you have not seen. Episodes the site
  lists with a countdown, before they are published, are **not** counted — each
  provider file filters them, and the server drops anything with a release date
  in the future as a second guard.
- **Says how many episodes you have not seen**, not how many are new. The badge
  on the poster reads `3 unseen` when three published episodes are waiting,
  whether they appeared today or a year ago.
- **Shows the real playing time on the poster.** The number at the bottom right,
  `MM:SS`, is how long the extension actually saw the episode on the card play —
  the one you are told to watch next, or the last one you finished. `00:15` next
  to a 65-minute episode means it was opened and closed; `00:00` means the
  extension never saw it at all, so it was watched on a computer where the
  extension was not running.
- **One dashboard for every computer.** All data lives on the server. Open
  `https://your-server/dashboard` anywhere — the extension is only needed on the
  computers where you actually watch.
- **No login screen when the extension is installed.** The extension swaps your
  API key for a one-time ticket and opens the dashboard already logged in.
- **Shows which account each site is logged in with.** These platforms sign you
  up by mobile number, and if you hold several, the subscription may sit on a
  different number for each site — logging in with the wrong one looks like the
  subscription vanished. The number appears next to the provider name, read
  while something is playing, which proves that account really is subscribed.

## New movie sites without a new extension

Support for a site is a **plain JSON file** on the server, in
`server/providers/`. Upload one file and every browser picks it up within six
hours (or immediately, from the extension's *Reload site scripts* button).

A provider file says three things:

| Part | What it does |
|---|---|
| `matches` | which sites the tracker should run on |
| `watch` | how to tell the show name, season and episode from a page |
| `catalog` | how the server lists every episode, to spot new ones |

These files are **data, not code** — URL patterns, JSON paths, CSS selectors and
regular expressions. The extension never runs downloaded JavaScript, so a
provider file cannot do anything else to your browser.

See [`docs/PROVIDERS.md`](docs/PROVIDERS.md) for the format. Four real ones ship
with it:

| File | Site | How it reads the show |
|---|---|---|
| [`filimo.json`](server/providers/filimo.json) | filimo.com | its public REST catalog API |
| [`sheyda.json`](server/providers/sheyda.json) | sheyda.com | its GraphQL API |
| [`filmnet.json`](server/providers/filmnet.json) | filmnet.ir | its public `/api-v2/` REST API |
| [`namava.json`](server/providers/namava.json) | namava.ir | its public `/api/v2.0/` REST API |

None of them needs a login **on the server**: the hourly check only uses the
parts of each site's API that are public. Your own browser session is used only
in the browser.

Namava adds one twist: it answers only Iranian IP addresses, and from anywhere
else it returns an empty list instead of an error — which would look exactly
like "this show has no new episodes". Its catalog therefore goes through a small
relay that runs in Iran; the relay is one PHP file, only forwards GET requests
to namava's API, and its address and key live in `config.php`, never in the
provider file the browser downloads. See
[DEPLOYMENT.md §13](DEPLOYMENT.md#13-the-iran-relay).

## Repository layout

```
server/
  public/            docroot: front controller, .htaccess, css/js
  src/               the PHP classes
  views/             login, dashboard, settings
  providers/         one JSON file per movie site
  relay/             the country relay (PHP + vhost), deployed on its own box
  migrations/        SQLite schema
  bin/sr.php         command line tool (users, checks, migrations)
  config.example.php copy to config.php on the server
extension/
  manifest.json      Chrome MV3
  background.js      provider list, report queue, dashboard login
  content/tracker.js watches the player, works out the episode
  options.*          the only two settings: API key and API URL
  popup.*            status and a button to the dashboard
tools/make-icons.js  regenerates the extension icons
docs/PROVIDERS.md    how to add a new movie site
DEPLOYMENT.md        how this is deployed and how to update it
```

## Install the extension

1. Open `chrome://extensions`.
2. Turn on **Developer mode**.
3. **Load unpacked** → pick the `extension/` folder.
4. Open the extension's **Settings** and paste:
   - **API key** — from the dashboard, *Settings → API key*
   - **API URL** — `https://serial-reminder.kimiasoft.com/api`
5. Press **Test connection**. It should say *Connected as …*.

That is all. Watch something, then open the dashboard.

> The extension asks for access to all sites. It needs that because the list of
> movie sites lives on the server, not in the extension — it cannot know in
> advance which sites to ask for. The tracker is only ever injected into the
> sites the provider scripts name.

## Server setup (short version)

Full detail, including the ger1 specifics, is in [DEPLOYMENT.md](DEPLOYMENT.md).

```bash
cp server/config.example.php server/config.php   # then edit it
php server/bin/sr.php migrate
php server/bin/sr.php user:add <username>        # prints the API key
php server/bin/sr.php check --force              # look for new episodes now
```

Hourly cron:

```
17 * * * * /usr/bin/php8.4 /path/to/app/bin/sr.php check --quiet
```

## API

Every call needs `Authorization: Bearer <api key>`.

| Method | Path | What it does |
|---|---|---|
| GET | `/api/ping` | check the key works |
| GET | `/api/providers` | the provider scripts |
| POST | `/api/watch` | report playing progress |
| GET | `/api/serials` | your shows, with the new-episode flags |
| GET | `/api/serials/{id}` | one show and all its episodes |
| PATCH | `/api/serials/{id}` | change status, title, notes |
| DELETE | `/api/serials/{id}` | stop following |
| POST | `/api/serials/{id}/mark-up-to` | mark this episode and earlier as seen |
| POST | `/api/check` | look for new episodes now |
| POST | `/api/account` | report which account a site is logged in with |
| GET | `/api/accounts` | the account on each site |
| DELETE | `/api/accounts/{provider}` | forget one |
| POST | `/api/session-ticket` | one-time dashboard login link |

## Privacy

The server stores show titles, episode numbers, how far you watched, and the
mobile number each site is logged in with. Nothing is sent anywhere else. The extension talks only to your own server and to the
site you are already watching.

## Licence

MIT.
