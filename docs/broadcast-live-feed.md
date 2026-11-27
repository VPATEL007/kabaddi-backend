# Statifyy Live Kabaddi Feed – Broadcaster Guide

Live match data for broadcasters and media partners: scores, team and player statistics, raid-by-raid play-by-play, cards, substitutions and the result.

Each time something happens in a match (a raid, a card, a substitution or a correction), we publish a new **version** of the full match data. You fetch the latest version whenever you need it.

| | |
|---|---|
| **How to get data now** | REST API. Poll every 3–5 seconds. |
| **Coming soon** | WebSocket push: updates within a second, no polling. See [WebSocket](#websocket-coming-soon). |

---

## 1. What you receive from us

| Item | Value |
|---|---|
| API base URL | `https://statifyy.com/api/v1/broadcast` |
| API key | Sent to you privately, for example `kbd_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx` |
| Access | The tournaments or matches assigned to your key |

Send the key in the `X-API-Key` header on every request. Keep it secret and use it from your servers only, not in public web pages or apps. If it leaks, tell us and we will issue a new one.

---

## 2. Endpoints

### 2.1 List matches

```
GET https://statifyy.com/api/v1/broadcast/matches
X-API-Key: <your key>
```

Optional filters: `status=live|scheduled|completed`, `date=YYYY-MM-DD`.

```json
{
  "matches": [
    {
      "match-id": "19",
      "tournament-id": "4",
      "title": "League Match 7",
      "start-date": "2026-11-27",
      "start-time": "18:30",
      "status": "first_half",
      "feed-enabled": true,
      "version": 14,
      "channel": "private-broadcast.match.19",
      "teams": [
        { "team-id": "1", "team-name": "Jordar Delhi" },
        { "team-id": "2", "team-name": "P Paltan" }
      ]
    }
  ]
}
```

A match appears here once its live feed has started.

### 2.2 Get the live match data

```
GET https://statifyy.com/api/v1/broadcast/matches/{matchId}/live
X-API-Key: <your key>
If-None-Match: "14"        (optional: the version you already have)
```

- **200** with the full match data. The `ETag` response header is the version, for example `"15"`.
- **304 Not Modified** with no body, if you sent `If-None-Match` and there is no newer version. This is fast and cheap, so use it when polling.

```bash
curl -i -H "X-API-Key: <your key>" \
  https://statifyy.com/api/v1/broadcast/matches/19/live
```

---

## 3. Polling: recommended setup

1. Call `/live` every **3–5 seconds** for each match you show.
2. Send the last version you received in `If-None-Match`.
3. On **304**, do nothing. On **200**, replace your data with the new response.
4. **Always keep the highest version.** Ignore a response whose `meta.version` is lower than the one you have.

```js
// Node.js 18+ example
const KEY = process.env.STATIFYY_API_KEY;
const MATCH_ID = 19;
let version = null;

async function poll() {
  const res = await fetch(`https://statifyy.com/api/v1/broadcast/matches/${MATCH_ID}/live`, {
    headers: { 'X-API-Key': KEY, ...(version ? { 'If-None-Match': `"${version}"` } : {}) },
  });

  if (res.status === 200) {
    const data = await res.json();
    if (version === null || data.meta.version > version) {
      version = data.meta.version;
      render(data);              // your code: update graphics / website
    }
  } else if (res.status !== 304) {
    console.error('Feed error', res.status, await res.text());
  }
}

setInterval(poll, 3000);
poll();
```

**Rate limit:** 60 requests per minute per API key by default, across all matches. Polling one match every 3 seconds uses 20. If you follow several matches at once, ask us for a higher limit.

---

## 4. Response format

Every response contains the **full match state**: a `meta` header plus the `in-match` data. All numbers inside `in-match` are strings.

```json
{
  "meta": {
    "match-id": "19",
    "tournament-id": "4",
    "status": "live",
    "current-half": "1",
    "version": 15,
    "event": "raid.completed",
    "last-raid-number": 14,
    "published-at": "2026-11-27T14:31:05+00:00",
    "score": { "1": 13, "2": 5 }
  },
  "in-match": {
    "team-players-statistics": { "team": [ { "...": "see 4.2" } ] },
    "play-by-play": { "raid": [ { "...": "see 4.3" } ] },
    "match-details": { "result": { "...": "see 4.4" } },
    "match-events": { "event": [ { "...": "see 4.5" } ] }
  }
}
```

### 4.1 meta

| Field | Meaning |
|---|---|
| `match-id`, `tournament-id` | Ids |
| `status` | `scheduled`, `live`, `timeout`, `completed` |
| `current-half` | `1` or `2` |
| `version` | Goes up by 1 on every update. Keep the highest. |
| `event` | What caused this version (see [Events](#5-events)) |
| `last-raid-number` | Number of the latest raid |
| `published-at` | When this version was published (ISO 8601) |
| `score` | Total points per team: `{ "<team-id>": <points> }` |

### 4.2 team-players-statistics.team[]

```
team-name, team-id, no-of-players-on-court
points
  total-points
  tackle-points { total-tackle-points, capture-points, tackle-bonus-points }
  raid-points   { total-raid-points, touch-points, raid-bonus-points }
  all-out-points
  extra-points
raids      { total-raids, super-raids, successful-raids, unsuccessful-raids, empty-raids }
tackles    { total-tackles, super-tackles, successful-tackles, unsuccessful-tackles }
do-or-die  { total-raids, successful-raids, failed-raids, super-raids, raid-points, touch-points, bonus-points }
players.player[]
  player-id, jersey-no, player-name
  player-on-court        "true"; only present when the player is on court
  player-revival-order   "1".."n"; only present when the player is out (1 = next to come back)
  points
    total-points
    raid-points   { total-raid-points, touch-points { total-touch-points }, raid-bonus-points }
    tackle-points { total-tackle-points, capture-points { total-capture-points }, tackle-bonus-points }
  raids, tackles, do-or-die   (same keys as the team)
  cards { green-card { count, #text }, yellow-card { count, #text }, red-card { count, #text } }
```

A player with neither `player-on-court` nor `player-revival-order` is a substitute (on the bench).

### 4.3 play-by-play.raid[]

One entry per raid, oldest first.

```
raid-number, raiding-team-id, raiding-player-id, player-jersey-no, raid-outcome-id, raid-half
team[0]   the raiding team (always first)
  team-id, total-points
  points { total-points,
           raid-points   { total-raid-points, touch-points, raid-bonus-points },
           tackle-points { total-tackle-points },
           all-out-points, extra-points }
team[1]   the defending team
  team-id, total-points
  points { total-points,
           raid-points   { total-raid-points },
           tackle-points { total-tackle-points, capture-points, tackle-bonus-points },
           all-out-points, extra-points }
```

The points are what each team scored **in that raid**.

**raid-outcome-id**

| Code | Meaning |
|---|---|
| `1` | Successful raid |
| `2` | Empty raid |
| `3` | Unsuccessful raid (raider tackled) |

Raid numbers can skip a number when a raid turn was skipped by the scorer.

### 4.4 match-details.result

| Field | Meaning |
|---|---|
| `Stage` | Stage name, for example `League Match` |
| `Half` | `1st Half` or `2nd Half` |
| `RaidingClubId` | Team id raiding next |
| `result-type` | `W` (win), `D` (draw), empty while the match is live |
| `winning-team-id` | Winner's team id, empty while live or for a draw |
| `#text` | Result text, for example `Kenya Beat Singapore  (39-26 )`. Empty while live. |

### 4.5 match-events.event[]

Cards, substitutions and technical points, oldest first.

| Field | Meaning |
|---|---|
| `type` | `card`, `substitution` or `technical-point` |
| `event-id`, `half`, `raid-number`, `time` | Common to all types |
| `team-id`, `player-id`, `card` | For cards. `card` is `green-card`, `yellow-card` or `red-card`. |
| `players-in[]`, `players-out[]` | For substitutions: `{ player-id, team-id }` |
| `team-id` | For technical points |

### 4.6 How points add up

- `total-points` = raid points + tackle points + all-out points + extra points
- `raid-points` = touch points + raid bonus points
- `tackle-points` = capture points + tackle bonus points (the extra point from a super tackle)
- `extra-points` = technical points + lineout points

### 4.7 Changes from the previous feed format

If you already use our earlier live scoring JSON:

- `successfull-raids` in `do-or-die` is now spelled `successful-raids`.
- Removed: `points-last-n-minutes`, `skill-points` (touch and capture points keep their totals), `time-off-court`, `avg-raid-time`, `raid-map-locations`, `phase-of-play`.
- Added: `meta` and `match-events`.

---

## 5. Events

`meta.event` tells you what caused the latest version.

| Event | When |
|---|---|
| `feed.started` | Live feed started for the match |
| `match.started` | Match moved from scheduled to live |
| `raid.completed` | A raid was recorded |
| `raid.skipped` | A raid turn was skipped |
| `technical.point` | Technical point awarded |
| `card.issued` | Green, yellow or red card |
| `substitution` | Players substituted |
| `lineup.updated` | Line-up changed |
| `match.status` | Status changed (half time, time-out) |
| `match.corrected` | The scorer edited or undid something. Replace your data with this version. |
| `match.ended` | Match completed, result included |
| `manual.publish` | Data re-sent by the scorer |

Corrections always come as a **new, higher version**, so keeping the highest version is always correct.

---

## 6. Errors

| HTTP | Meaning |
|---|---|
| 200 | OK |
| 304 | Nothing new since the version you sent |
| 401 | Missing or invalid API key |
| 403 | Your key has no access to this match |
| 404 | Match not found, or its live feed has not started yet |
| 422 | Invalid request parameters |
| 429 | Rate limit reached. Wait a few seconds and try again. |

Error responses have a JSON body: `{ "message": "..." }`.

---

## WebSocket (coming soon)

We are adding a WebSocket channel so you receive each update within a second, without polling. When it is ready we will send you:

- the WebSocket address (`wss://...`) and a public app key
- a short setup guide with sample code

It uses the widely supported Pusher protocol, so free client libraries exist for JavaScript, Android, iOS, Python, .NET and others. Each message will contain the same full match data as `/live`, plus the latest raid.

Until then, polling `/live` as in section 3 gives you the same data.

---

## Support

For a new key, access to more matches, a higher rate limit or questions about the data, contact the Statifyy team.
