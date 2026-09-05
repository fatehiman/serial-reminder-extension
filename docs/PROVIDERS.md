# Adding a new movie site

A "provider" is one JSON file in `server/providers/`. Upload it and you are done:

- the **server** uses it to look for new episodes,
- the **extension** downloads it and starts watching that site — no new
  extension version, no reinstall.

The extension refreshes the list every 6 hours, or immediately from
*extension Settings → Reload site scripts*.

> **These files are data, never code.** The extension does not run downloaded
> JavaScript. It only follows URL patterns, JSON paths, CSS selectors and regular
> expressions. Keep it that way — it is what makes it safe to load a provider
> file from the server.

---

## The shape of the file

```jsonc
{
  "name":    "filimo",              // used as the id everywhere. Match the filename.
  "label":   "Filimo",              // shown to the user
  "version": 3,                     // bump it when you edit the file
  "enabled": true,

  "matches": ["*://www.filimo.com/*"],   // Chrome match patterns

  "watch":   { ... },   // used in the browser: what is playing right now?
  "catalog": { ... }    // used on the server: which episodes exist?
}
```

---

## `watch` — reading the page in the browser

```jsonc
"watch": {
  // Only pages whose URL matches this are tracked. Capture groups become
  // variables, named by urlFields.
  "urlPattern": "^https?://(?:www\\.)?filimo\\.com/w/([A-Za-z0-9_-]+)",
  "urlFields":  ["episodeKey"],

  // Which player to follow. Defaults to "video".
  "video": { "selector": "video" },

  "enrich":   { ... },   // preferred: ask the site's own API
  "fallback": { ... }    // if that fails: read the page title
}
```

The tracker needs three things before it will report anything:
`seriesKey`, `seriesTitle` and `episode`. Everything else is optional.

Reporting is not the same as following. The server keeps the reports, but the
show only appears on the dashboard once one episode passes the watched rule
(70% of the player, or 20 minutes when the length is unknown). So a wrong
`urlPattern` that matches a browse page costs nothing — it just never confirms.

| Field | Meaning |
|---|---|
| `seriesKey` | stable id of the **show** on that site. Not the episode. |
| `seriesTitle` | the show name |
| `season` | defaults to 1 |
| `episode` | episode number — required |
| `episodeTitle`, `episodeUrl`, `seriesUrl`, `poster` | nice to have |

### `enrich` — use the site's own API (best)

Most streaming sites are single page apps with scrambled CSS class names, so
reading the DOM breaks often. Their internal JSON API is far more stable, and
the request is same-origin so your login cookie is sent automatically.

```jsonc
"enrich": {
  "url": "https://www.filimo.com/api/fa/v1/movie/movie/one/uid/{episodeKey}",
  "cacheSeconds": 21600,
  "requireTruthy": "data.attributes.General.serial.enable",  // skip plain films
  "fields": {
    "seriesKey":   { "path": "data.attributes.General.serial.parent_id", "as": "string" },
    "seriesTitle": "data.attributes.General.serial.title",
    "episode":     { "path": "data.attributes.General.serial.serial_part", "as": "int" },
    "seriesUrl":   "https://www.filimo.com/m/{data.attributes.General.serial.title_seo}"
  },
  "lowercase": ["seriesUrl"]
}
```

Finding the right URL takes five minutes: open a show, press F12 → **Network** →
filter **Fetch/XHR**, and look for the request that carries the episode number.

For a **GraphQL** site, add `method` and `body`. Placeholders work inside the
body too:

```jsonc
"enrich": {
  "url": "https://api.sheyda.com/query",
  "method": "POST",
  "headers": { "did": "serial-reminder-web-0000…", "x-source-p": "202314" },
  "body": {
    "variables": { "uid": "{episodeKey}" },
    "query": "query($uid: String!) { getEpisodePageByUid(uid: $uid) { data { episode { uid title } } } }"
  },
  "fields": { "episode": { "path": "data.getEpisodePageByUid.data.episode.title", "as": "int" } }
}
```

**Cookies.** The request is sent with `credentials: "same-origin"`, so a call to
the site's own domain carries your login. Set `"credentials": "include"` only if
a cross-origin API really needs the cookie — and test it, because some gateways
answer 504 to a cross-origin request that carries credentials (sheyda's does).

### Picking a row out of a list

`find` searches a list and `pick` takes one field out of the row it found. Use it
whenever the value you want lives in a *different* part of the response, keyed by
an id — a season name, for instance:

```jsonc
"season": {
  "path": "data.getEpisodePageByUid.data.program.seasons",
  "find": { "id": "{data.getEpisodePageByUid.data.episode.seasonID}" },
  "pick": "title",
  "as": "int",
  "map": { "فصل اول": 1, "فصل دوم": 2 }
}
```

> **Do not read the season out of `document.title`.** These are single page apps:
> when you click the next episode the URL changes immediately but the title still
> says the old one for a moment, and the tracker looks straight away. That
> mistake put a season-2 episode into season 1 on a live account. Take the value
> from the API.

### Turning words into numbers

Some sites name seasons instead of numbering them. `map` fixes that: it tries an
exact match first, then looks for a key inside the text, longest key first.

```jsonc
"season": {
  "template": "{title}",           // {title} is the page title
  "as": "int",
  "default": 1,
  "map": { "فصل اول": 1, "فصل دوم": 2, "فصل سوم": 3 }
}
```

### `fallback` — read the page title

```jsonc
"fallback": {
  "source":  "document.title",
  "pattern": "^(.+?) - فصل ([0-9۰-۹]+) قسمت ([0-9۰-۹]+)(?::\\s*(.*))?$",
  "fields":  ["seriesTitle", "season", "episode", "episodeTitle"],
  "seriesKeyFrom": "seriesTitle",   // makes a key like "title:badnam"
  "episodeUrl": "{url}"
}
```

Use `"selector": ".show-name"` instead of `source` to read an element.

`fallback` only runs when `enrich` is missing or failed, and it carries the same
timing risk described above: on an in-page navigation the title can still be the
previous episode's. Treat it as a safety net for when the API changes, not as the
normal path. If a site gives you no usable title at all — filmnet's is just
"فیلمنت" on every page — write no fallback rather than one that pretends to work.

---

## `account` — which mobile number is logged in

Every Iranian platform signs you up by mobile number, and one person often holds
several, with the subscription on a different number for each site. Logging in
with the wrong one looks exactly like "my subscription vanished". The dashboard
shows the number next to the provider, so the mistake is obvious.

The extension reads it **only while something is really playing**, which is proof
that this account's subscription works, and sends it at most once every
`refreshHours` (or immediately when the number changes).

Three ways to get it. Prefer whichever needs no network call.

```jsonc
// 1. the site already keeps it in the browser (filmnet)
"account": {
  "refreshHours": 20,
  "source": "localStorage",        // or sessionStorage, or cookie
  "key": "lscache-state",
  "decode": "json",                // json | jwt | none
  "fields": {
    "label": { "path": "user.msisdn", "as": "phone" },   // shown in the dashboard
    "name":  "user.name",
    "note":  "user.subscription_package_name"
  }
}

// 2. it is a claim inside the token the site stores (sheyda)
"account": {
  "source": "localStorage", "key": "atk", "decode": "jwt",
  "fields": { "label": { "path": "m", "as": "phone" } }
}

// 3. ask the site's own profile API (filimo) — same origin, so the cookie goes too
"account": {
  "source": "url",
  "url": "https://www.filimo.com/api/fa/v1/web/config/uxEvent",
  "fields": { "label": { "path": "data.user.mobile", "as": "phone" },
              "name":  "data.user.name" }
}
```

`label` is required and is what the dashboard shows; `as: "phone"` normalises
`989133169571` and `+98 913 316 9571` both to `09133169571`. `name` and `note`
are optional extras shown when you hover.

To find it: log in, open the site's profile page with F12 → **Network**, and look
for the response carrying your number. Then check whether the site already has it
in `localStorage` — usually it does, and then no request is needed at all.

---

## `catalog` — listing episodes on the server

This is what finds new episodes. It is a list of steps; each one fetches URLs and
turns the answer into rows. A step can loop over the rows of an earlier step.

```jsonc
"catalog": {
  // Some APIs want an episode id, not a series id. This says where to get it.
  "refKeyFrom":    "episodeUrl",     // or "seriesKey"
  "refKeyPattern": "~/w/([A-Za-z0-9_-]+)~",   // PHP regex, with delimiters
  "maxRequests":   12,
  "headers":       { "Accept": "application/json" },

  "steps": [
    {
      "id":   "seasons",
      "url":  "https://site/api/allseason/uid/{refKey}",
      "type": "json",                       // "json" or "html"
      "list": "data.attributes.data",       // path to the array
      "fields": {
        "season":      { "path": "serial_season_part", "as": "int" },
        "episodesUrl": "episodes_link"
      }
    },
    {
      "id":      "episodes",
      "forEach": "seasons",                 // run once per row of that step
      "url":     "{item.episodesUrl}",      // "item" is the parent row
      "type":    "json",
      "list":    "included",
      "emit":    true,                      // only emitted steps produce episodes
      "fields": {
        "number":     { "path": "attributes.serial_part", "as": "int" },
        "season":     { "path": "attributes.serial_season_part", "as": "int" },
        "title":      "attributes.movie_title",
        "url":        "https://site/w/{attributes.uid}",
        "duration":   { "path": "attributes.duration", "as": "int" },
        "releasedAt": "attributes.publish_date"
      },
      "skipWhen": [
        { "path": "attributes.timeLeftToPublish", "gt": 0 },
        { "path": "attributes.uid", "empty": true }
      ]
    }
  ]
}
```

A step can `POST` as well, which is how a GraphQL catalog is read:

```jsonc
{
  "id": "episodes", "forEach": "seasons", "emit": true,
  "url": "https://api.sheyda.com/query",
  "method": "POST",
  "type": "json",
  "body": {
    "variables": { "seasonID": "{item.seasonId}" },
    "query": "query($seasonID: String!) { getSeasonEpisodes(seasonID: $seasonID) { data { uid title releaseStatus } } }"
  },
  "list": "data.getSeasonEpisodes.data",
  "fields": { "number": { "path": "title", "as": "int" } },
  "skipWhen": [ { "path": "releaseStatus", "ne": "RELEASED" } ]
}
```

Fields a step does not set are inherited from its `forEach` parent, so the
`season` worked out in the seasons step is carried onto every episode.

### Variables available in a URL template

`{refKey}` `{seriesKey}` `{seriesUrl}` `{title}` and `{item.<field>}` from the
step named in `forEach`.

### `skipWhen` conditions

The row is dropped when **any** condition is true.

| Condition | True when |
|---|---|
| `{"path": "x", "empty": true}` | `x` is missing or blank |
| `{"path": "x", "empty": false}` | `x` has a value |
| `{"path": "x", "truthy": true}` | `x` is truthy |
| `{"path": "x", "eq": "foo"}` | `x` equals `"foo"` |
| `{"path": "x", "ne": "foo"}` | `x` is anything **but** `"foo"` |
| `{"path": "x", "gt": 0}` | `x` as a number is greater than 0 |
| `{"path": "x", "matches": "~re~"}` | the PHP regex matches |
| `{"path": "x", "future": true}` | `x` is a date still in the future |

**Always skip unreleased episodes.** Sites list "coming soon" entries, and
without a skip rule every show would look like it has a new episode.

### Episodes nested inside seasons

When one response holds seasons that each hold their episodes, point `list` at
the nested path and add `flatten`:

```jsonc
{
  "id": "episodes", "emit": true,
  "url": "https://filmnet.ir/api-v2/video-contents/{refKey}",
  "list": "data.seasons.*.episodes",
  "flatten": true,
  "fields": { "number": { "path": "episode", "as": "int" },
              "season": { "path": "season", "as": "int" } }
}
```

### `html` instead of `json`

For a site with no API, set `"type": "html"`, put a PHP regex with the `m`
modifier in `list`, and map fields to numbered groups:

```jsonc
{
  "id": "episodes", "type": "html", "emit": true,
  "url": "{seriesUrl}",
  "list": "~<a href=\"(/watch/[^\"]+)\"[^>]*>\\s*Episode\\s+(\\d+)~i",
  "fields": {
    "url":    "https://site{1}",
    "number": { "path": "2", "as": "int" }
  }
}
```

---

## Field rules

A field is one of:

| Written as | Means |
|---|---|
| `"a.b.c"` | read that path out of the row |
| `"https://x/{a.b}"` | a template; `{...}` are paths |
| `{"path": "a.b", "as": "int"}` | read and convert |
| `{"path": "a.b", "as": "int", "default": 1}` | …with a fallback value |
| `{"path": "a.b", "map": {"one": 1}}` | …translating words into values first |
| `{"path": "list", "find": {"id": "{x}"}, "pick": "name"}` | find a row in a list, take one field |

`as` also understands `duration` (`"01:05:39"` → 3939 seconds) and `phone`
(`989133169571` → `09133169571`).

`as` can be `int`, `float`, `bool` or `string`. Persian and Arabic digits
(`۲۲`, `٢٢`) are converted to normal numbers automatically.

Use `*` in a path to collect every element of an array: `data.*.title`.

---

## Building a new provider, step by step

This is the order the three existing providers were actually written in. It
takes about half an hour per site.

### 1. Watch the site do the work

Log in, open a **serial** (not a film), and start an episode. Then press F12 →
**Network** → filter **Fetch/XHR**, and reload the page.

You are looking for the one response that names the episode. On all three sites
so far it existed and was easy to spot. Note down:

- the **watch page URL** shape (`/w/{id}`, `/play/{id}/p`, …) → `urlPattern`
- the request that returns the episode → `enrich`
- the request that returns the whole series → `catalog`
- any **custom headers** the site sends (`did`, `x-source-p`, `Authorization`, …)

### 2. Find out what the server is allowed to call

This is the important one, because the hourly check runs with no login at all.
Replay the catalog request with `curl`, from your machine and then from the
server, dropping the headers one at a time:

```bash
curl -s -o /dev/null -w '%{http_code}
' 'https://site/api/...'          # nothing
curl -s ... -H 'did: anything' -H 'x-source-p: 202314' 'https://site/...'  # gateway headers only
```

Very often the catalog is public and only the *user's* endpoints need a token.
Sheyda is exactly that: `getEpisodeByUid` demands the JWT, but
`getEpisodePageByUid` returns the same episode and is public. Look for the
public twin before giving up — do not put a login token on the server.

If a request needs a device id, try a made-up one. All three sites accepted any
string.

### 3. Write the file and test the server half

```bash
php server/bin/sr.php providers      # valid JSON? correct name and matches?
```

Then run the catalog directly, without needing a show in the database yet:

```bash
php -r 'require "server/bootstrap.php";
  $p = SR\Providers::get("newsite");
  $r = SR\Catalog::fetch($p, ["seriesKey" => "…", "refKey" => "…"]);
  echo count($r["episodes"]), " episodes, error=", var_export($r["error"], true), "
";
  print_r(array_slice($r["episodes"], 0, 3));'
```

Check the season and episode numbers against what the site's own page shows.
That is where the mistakes are.

### 4. Test the browser half against the real page

The `watch` half runs in the browser, so test it there rather than guessing.
Pull the helper functions straight out of the shipped tracker so you are testing
the real code, and run the `enrich` block in the page's console:

```bash
# everything from "function digits(" up to the "detection" banner
sed -n '/^  function digits(/,/detection \*\//p' extension/content/tracker.js
```

Paste that into the console on a watch page, then paste your `enrich` spec and
call the same steps `detect()` does. Compare the result with the page.

**Test it with a deliberately wrong `document.title`.** A single page app changes
the URL before the title, so anything read from the title is a race. That bug
filed a season-2 episode as season 1 on a live account.

### 5. Try it for real

```bash
scp server/providers/newsite.json Ger1-root:/tmp/
ssh Ger1-root 'D=/mnt/ger_hd1/www/serial-reminder
  mv /tmp/newsite.json $D/app/providers/
  chown serial-reminder:serial-reminder $D/app/providers/newsite.json
  sudo -u serial-reminder php8.4 $D/app/bin/sr.php providers'
```

Then in Chrome: extension **Settings → Reload site scripts**, and play an
episode. Watch it arrive:

```bash
ssh Ger1-root 'sqlite3 -header -column /mnt/ger_hd1/www/serial-reminder/data/serial-reminder.sqlite   "SELECT s.provider, e.season, e.number, e.watched_seconds, e.position_seconds
     FROM episodes e JOIN serials s ON s.id = e.serial_id WHERE s.provider = \"newsite\";"'
```

No PHP changed, so no opcache flush is needed for a provider-only change.

---

## Testing and debugging

```bash
php server/bin/sr.php providers                 # is the file valid JSON?
php server/bin/sr.php check --serial=<id> --force
php server/bin/sr.php serials                   # what did it find?
```

For the browser half: open a page on the site, press F12, and run
`window.__srDebug = true` in the console, then reload. The tracker will print
what it detected and every report it sends.

If nothing happens at all:

1. Is the site in `matches`? Check *extension Settings → Site scripts loaded*.
2. Press **Reload site scripts** — Chrome caches the list for 6 hours.
3. Does `urlPattern` really match the watch page URL?
4. Is the tab one that was **already open** before the extension last reloaded?
   It should be picked up automatically now, but reloading the page settles it.
5. Check the server log for what did or did not arrive:
   `ssh Ger1-root 'grep "POST /api/watch" /mnt/ger_hd1/www/serial-reminder/logs/access_log | tail'`

## Mistakes these three sites actually made us fix

Worth reading before writing a fourth — every one of these was a real bug.

| Trap | What happened |
|---|---|
| An `order` field that is not the episode number | Sheyda counts position in the season, and season 2 opens with a special, so `order: 7` is episode 6. Take the number from the title. |
| Reading anything from `document.title` | The SPA updates the URL first. A season-2 episode was filed as season 1. |
| Unreleased episodes in the list | Every site lists them, each with a different flag (`timeLeftToPublish`, `releaseStatus`, `published_at`). Miss it and every show looks like it has a new episode. |
| The player resuming near the end | Opening an episode can put the position at 90% in two seconds. Never treat position alone as proof. |
| `credentials: "include"` on a cross-origin API | Sheyda's gateway answers **504**. `same-origin` still sends cookies to a site's own API. |
| A player that is not there yet | Filmnet's video.js element appears about two seconds after load. The default `video` selector is right, it just needs patience. |
| Durations as text | `"01:05:39"` needs `as: "duration"`, otherwise it reads as the number 1. |
