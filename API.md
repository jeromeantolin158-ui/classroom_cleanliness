# SpotLess JSON API

Read-only endpoints for admins. They return JSON and use the same login session as the website, so sign in as an
admin first. Every error looks like `{"ok": false, "error": "message"}`.

| Status | Meaning |
|---|---|
| 200 | OK |
| 400 | Bad parameter (for example `week=2026-46`) |
| 401 | Not signed in |
| 403 | Signed in but not an admin, or a temporary password must be changed first |
| 405 | Wrong method, only `GET` is allowed |

## GET /api/ratings.php
Ratings for one week, newest first.

| Parameter | Default | Notes |
|---|---|---|
| `week` | this week | Format `2026-W46` |
| `building` | all | Exact building name |
| `max_score` | 5 | `2` returns only low ratings (1 to 2) |
| `limit` | 50 | 1 to 200 |
| `offset` | 0 | For paging |

```json
{
  "ok": true,
  "week": "2026-W46", "from": "2026-11-09", "to": "2026-11-15",
  "total": 48, "average": 3.8, "limit": 50, "offset": 0,
  "ratings": [
    {
      "id": 277, "date": "2026-11-12", "rated_at": "2026-11-12 15:59:00", "score": 1,
      "comment": "Room was very messy and needs attention.",
      "room": { "id": 11, "number": "103", "building": "Main building" },
      "rated_section": "BSBA 2-A", "rated_by": "BSBA 1-A"
    }
  ]
}
```
`rated_section` is the class that left the room, and `rated_by` is the class that rated it.

## GET /api/room_ranking.php
Rooms ranked by average rating for the week, cleanest first (ties go to the room with more ratings).
Parameters: `week`, `building`.

```json
{ "ok": true, "week": "2026-W46", "from": "2026-11-09", "to": "2026-11-15",
  "rooms": [ { "rank": 1, "room": { "id": 1, "number": "101", "building": "Main building" }, "ratings": 7, "average": 4.4 } ] }
```

## Try it
Sign in as admin in the browser, then open `http://localhost/classroom/api/ratings.php?max_score=2` in a new tab,
or run this in the browser console:

```js
fetch('/classroom/api/room_ranking.php').then(r => r.json()).then(console.log);
```

## Design notes
- Authentication reuses the PHP session. The API answers `401`/`403` in JSON instead of redirecting to the login page.
- Input is validated (strict week format, integer limits) and every query uses prepared statements.
- `no-store` caching, so the data is never served stale.
