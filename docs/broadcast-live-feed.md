# Broadcaster Live Feed

Live match data for broadcasters and media partners. Every time a raid is logged, the backend builds the full match JSON and pushes it over WebSocket within a second.

- **Main channel:** WebSocket (Laravel Reverb, Pusher protocol). The partner connects once and receives every update.
- **Catch-up:** REST endpoint for the current state, used on connect and reconnect.

---

## Part 1: Partner integration guide

### What you receive from us

| Item | Example |
|---|---|
| API key | `kbd_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx` |
| WebSocket app key (public) | `abc123` |
| WebSocket host | `ws.example.com`, port 443, TLS |
| API base URL | `https://api.example.com/api/v1/broadcast` |

Send the API key in the `X-API-Key` header on every REST call. Keep it secret.

### Step 1: find the match

```
GET /api/v1/broadcast/matches?status=live&date=2026-11-26
X-API-Key: <key>
```

Returns the matches you have access to, each with its `match-id`, teams, status, current `version` and WebSocket `channel`. `status` can be `live`, `scheduled` or `completed`.

### Step 2: load the current state

```
GET /api/v1/broadcast/matches/{matchId}/live
X-API-Key: <key>
If-None-Match: "42"        (optional)
```

Returns the full snapshot. The `ETag` header is the version number. If you send `If-None-Match` with the version you already have and nothing has changed, you get `304 Not Modified` with no body.

### Step 3: subscribe to live updates

Channel: `private-broadcast.match.{matchId}`. Event: `feed.updated`.

JavaScript (pusher-js). Libraries for Android, iOS, Python, .NET and others work the same way.

```js
import Pusher from 'pusher-js';

const pusher = new Pusher('<websocket app key>', {
  wsHost: 'ws.example.com',
  wsPort: 443,
  wssPort: 443,
  forceTLS: true,
  enabledTransports: ['ws', 'wss'],
  cluster: 'mt1', // required by pusher-js, ignored by our server
  channelAuthorization: {
    endpoint: 'https://api.example.com/api/v1/broadcast/socket-auth',
    headers: { 'X-API-Key': '<api key>' },
  },
});

let currentVersion = 0;

pusher.subscribe('private-broadcast.match.1024')
  .bind('feed.updated', (msg) => {
    if (msg.version <= currentVersion) return;          // old or repeated update
    if (msg.version > currentVersion + 1 && currentVersion > 0) {
      // We missed one. msg.data is the full state, so it is still safe to use.
    }
    currentVersion = msg.version;
    render(msg.data);
  });

// On connect and after every reconnect, call GET /live once so the screen is current right away.
pusher.connection.bind('connected', () => loadCurrentState());
```

### Message format (WebSocket)

```json
{
  "event": "raid.completed",
  "match-id": "1024",
  "version": 42,
  "latest-raid": { "raid-number": "63", "raid-outcome-id": "1", "...": "..." },
  "data": { "meta": { ... }, "in-match": { ... } }
}
```

Every message carries the **full match state** in `data`, plus the raid that just happened in `latest-raid`. If you miss a message, the next one still has everything.

### Events

| Event | When |
|---|---|
| `feed.started` | Feed switched on for the match |
| `match.started` | Match moved from scheduled to live |
| `raid.completed` | A raid was logged |
| `raid.skipped` | A raid turn was skipped |
| `technical.point` | Technical point awarded |
| `card.issued` | Green, yellow or red card |
| `substitution` | Players substituted |
| `lineup.updated` | Line-up changed before or during the match |
| `match.status` | Status changed (half, time-out) |
| `match.corrected` | Scorer edited or undid something. Replace your data with this version. |
| `match.ended` | Match completed, result included |
| `manual.publish` | Scorer pressed Push now or Re-publish |

**Always keep the highest version.** After a correction the version still goes up, so replacing with the highest version is always correct.

### Snapshot format

Same structure and key names as the client's existing live scoring JSON, plus a `meta` header and a `match-events` list. All numbers inside `in-match` are strings.

```
meta
  match-id, tournament-id
  status            scheduled | live | timeout | completed
  current-half, version, event, last-raid-number, published-at
  score             { "<team-id>": <total> }
in-match
  team-players-statistics.team[]
    team-name, team-id, no-of-players-on-court
    points: total-points
            tackle-points { total-tackle-points, capture-points, tackle-bonus-points }
            raid-points   { total-raid-points, touch-points, raid-bonus-points }
            all-out-points, extra-points
    raids:     total-raids, super-raids, successful-raids, unsuccessful-raids, empty-raids
    tackles:   total-tackles, super-tackles, successful-tackles, unsuccessful-tackles
    do-or-die: total-raids, successful-raids, failed-raids, super-raids, raid-points, touch-points, bonus-points
    players.player[]
      player-id, jersey-no, player-name
      player-on-court: "true"        only present when the player is on court
      player-revival-order: "1".."n" only present when the player is out (1 = next to come back)
      points { total-points,
               raid-points   { total-raid-points, touch-points { total-touch-points }, raid-bonus-points },
               tackle-points { total-tackle-points, capture-points { total-capture-points }, tackle-bonus-points } }
      raids, tackles, do-or-die (same keys as team)
      cards { green-card { count, #text }, yellow-card {...}, red-card {...} }
  play-by-play.raid[]
    raid-number, raiding-team-id, raiding-player-id, player-jersey-no, raid-outcome-id, raid-half
    team[0] (raiding team):   team-id, total-points, points { total-points,
                              raid-points { total-raid-points, touch-points, raid-bonus-points },
                              tackle-points { total-tackle-points }, all-out-points, extra-points }
    team[1] (defending team): team-id, total-points, points { total-points,
                              raid-points { total-raid-points },
                              tackle-points { total-tackle-points, capture-points, tackle-bonus-points },
                              all-out-points, extra-points }
  match-details.result
    Stage, Half ("1st Half" | "2nd Half"), RaidingClubId (team raiding next),
    result-type ("W" | "D" | "" while live), winning-team-id,
    #text ("Kenya Beat Singapore  (39-26 )", empty while live)
  match-events.event[]            (new)
    type: card | substitution | technical-point
    event-id, half, raid-number, time, plus team-id / player-id / card / players-in / players-out
```

Changes from the client's existing format:
- `successfull-raids` in `do-or-die` is spelled `successful-raids`.
- Removed: `points-last-n-minutes`, `skill-points`, `time-off-court`, `avg-raid-time`, `raid-map-locations`, `phase-of-play`.
- Added: `meta`, `match-events`.

**Raid outcome codes:** `1` successful, `2` empty, `3` unsuccessful (as in the client's sample data).

**Points:**
- `extra-points` = technical points + lineout points.
- `tackle-bonus-points` = super tackle extra point.
- `total-points` = raid points + tackle points + all-out points + extra points.

### Limits and errors

| Code | Meaning |
|---|---|
| 401 | Missing or invalid API key |
| 403 | No access to this match |
| 404 | Match not found, or its feed has not started |
| 429 | More than your per-minute limit (default 60) on REST |

The WebSocket has no rate limit.

---

## Part 2: Internal setup

### Install (one time)

```bash
composer require laravel/reverb   # brings pusher/pusher-php-server
php artisan migrate
```

`config/reverb.php` and `config/broadcasting.php` are already in the repo. If `reverb:install` or `install:broadcasting` asks to overwrite them, answer **no**, because ours raise the message size limit.

Set in `.env` (see `.env.example`):

```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<random>
REVERB_APP_KEY=<random>        # public, given to partners
REVERB_APP_SECRET=<random>     # private
REVERB_HOST=ws.example.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_BACKEND_HOST=127.0.0.1
REVERB_BACKEND_PORT=8080
REVERB_BACKEND_SCHEME=http
```

Recommended: `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis` for speed.

### Run Reverb (Supervisor)

```ini
[program:reverb]
command=php /var/www/kabaddi-backend/artisan reverb:start
autostart=true
autorestart=true
user=www-data
minfds=10000
```

### Nginx (wss on 443, proxied to Reverb on 8080)

```nginx
server {
    listen 443 ssl;
    server_name ws.example.com;
    # ssl_certificate ...

    location / {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_pass http://127.0.0.1:8080;
    }
}
```

Above about 1,000 connections, raise the Linux open-files limit (`ulimit -n`) and install the `ext-uv` PHP extension.

### Add a broadcaster

```bash
php artisan broadcast:partner "Star Sports" --tournament=12          # prints the API key once
php artisan broadcast:partner --id=1 --match=1024                    # add access
php artisan broadcast:partner --id=1 --rotate-key                    # new key
php artisan broadcast:partner --id=1 --deactivate
```

### Scoring page: Broadcast Feed panel (logged-in scorer, `auth:api`)

Only the match creator or the tournament creator can use these.

| Method | Endpoint | Body | Purpose |
|---|---|---|---|
| GET | `/api/match/{id}/broadcast` | | Status: enabled, mode, current_version, last_published_at |
| POST | `/api/match/{id}/broadcast/enable` | `mode` (auto/manual, optional) | Turn on and publish version 1 |
| POST | `/api/match/{id}/broadcast/disable` | | Turn off. The last version stays readable. |
| POST | `/api/match/{id}/broadcast/mode` | `mode` | Switch between Auto and Manual |
| POST | `/api/match/{id}/broadcast/publish` | | Push now (Manual) or Re-publish (both modes) |

**Auto mode** publishes after every change to the match: raid save, edit, undo or skip, technical points, cards, substitutions, line-up and status changes. The publish runs after the scorer's response has been sent, so scoring is not slowed down. Several changes in one request become one version.

### How it works

```
Scorer saves raid -> RaidService (DB) -> response to scorer
                                     \-> (after response) BroadcastPublisher
                                           - lock match_broadcast_states row, version + 1
                                           - LiveFeedBuilder builds the JSON from the DB
                                           - save match_feed_snapshots row, update cache
                                           - MatchFeedUpdated -> Reverb -> all subscribed partners
```

| File | Role |
|---|---|
| `app/Services/Broadcast/LiveFeedBuilder.php` | DB to client JSON. Same scoring rules as ScoreboardService. |
| `app/Services/Broadcast/BroadcastPublisher.php` | Versioning, snapshot, cache, WebSocket push |
| `app/Events/MatchFeedUpdated.php` | WebSocket event |
| `app/Http/Controllers/Broadcast/*` | Scoring panel and partner API |
| `app/Console/Commands/CreateBroadcastPartner.php` | Partner and key management |

If Reverb is down, publishing still saves the snapshot (the error is logged), and partners can fetch it over REST.
