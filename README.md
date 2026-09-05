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
- **Decides when an episode is finished.** An episode counts as watched when the
  player reaches **70%** of it, or when it played for **20 minutes** and the
  length is unknown. 70%, not more, because people skip ads, the recap at the
  start and the credits at the end. Both numbers are in `config.php`.
- **Only follows shows you really watch.** Opening a page and sampling a minute
  does **not** add it to your list. A show joins the list the first time one of
  its episodes passes the rule above. Until then the progress is still counted,
  quietly, so watching 15 minutes today and 15 tomorrow still adds up.
- **Finds new episodes by itself.** Every hour the server asks each show's site
  which episodes exist and marks the ones you have not seen.
- **One dashboard for every computer.** All data lives on the server. Open
  `https://your-server/dashboard` anywhere — the extension is only needed on the
  computers where you actually watch.
- **No login screen when the extension is installed.** The extension swaps your
  API key for a one-time ticket and opens the dashboard already logged in.

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

See [`docs/PROVIDERS.md`](docs/PROVIDERS.md) for the format. Two real ones ship
with it:

| File | Site | How it reads the show |
|---|---|---|
| [`filimo.json`](server/providers/filimo.json) | filimo.com | its public REST catalog API |
| [`sheyda.json`](server/providers/sheyda.json) | sheyda.com | its GraphQL API |

Neither needs a login **on the server**: the hourly check only uses the parts of
each site's API that are public. Your own browser session is used only in the
browser.

## Repository layout

```
server/
  public/            docroot: front controller, .htaccess, css/js
  src/               the PHP classes
  views/             login, dashboard, settings
  providers/         one JSON file per movie site
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
| POST | `/api/session-ticket` | one-time dashboard login link |

## Privacy

The server stores show titles, episode numbers and how far you watched. Nothing
is sent anywhere else. The extension talks only to your own server and to the
site you are already watching.

## Licence

MIT.
