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

**Always skip unreleased episodes.** Sites list "coming soon" entries, and
without a skip rule every show would look like it has a new episode.

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

`as` can be `int`, `float`, `bool` or `string`. Persian and Arabic digits
(`۲۲`, `٢٢`) are converted to normal numbers automatically.

Use `*` in a path to collect every element of an array: `data.*.title`.

---

## Testing a new provider

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
