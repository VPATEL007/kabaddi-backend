# Broadcaster Live Feed – Internal Guide

**Internal only. Do not share with broadcasters.** The broadcaster-facing guide is [broadcast-live-feed.md](broadcast-live-feed.md). Deployment steps are in [deployment-broadcast-feed.md](deployment-broadcast-feed.md).

---

## Current production status (statifyy.com)

| Part | Status |
|---|---|
| REST feed (`/api/v1/broadcast/...`) | **Live** |
| Scoring-panel endpoints | **Live** |
| WebSocket push (Reverb) | **Not yet.** Production is on Hostinger shared hosting, which can't run Reverb. Needs a small separate VPS. Until then `BROADCAST_CONNECTION=null`. |

When the Reverb VPS is ready: set the `REVERB_*` values in production `.env` to point at it, set `BROADCAST_CONNECTION=reverb`, then update the WebSocket section in the broadcaster guide and send broadcasters the public `REVERB_APP_KEY` and `wss://` address.

---

## Add or manage a broadcaster

Run on the server, in the project folder:

```bash
php artisan broadcast:partner "Star Sports" --tournament=12          # create; prints the API key ONCE
php artisan broadcast:partner --id=1 --match=1024                    # add access to a match
php artisan broadcast:partner --id=1 --tournament=15                 # add access to a tournament
php artisan broadcast:partner --id=1 --rotate-key                    # new key; the old one stops working
php artisan broadcast:partner --id=1 --deactivate                    # turn off
php artisan broadcast:partner "Big Partner" --rate-limit=180         # higher REST limit (requests/minute)
```

- One key per broadcaster. Send it privately and never in a group chat.
- Access by tournament covers every match in it, including matches created later.
- The default limit is 60 REST requests per minute per key. A broadcaster polling several matches every 3 seconds needs more (for example `--rate-limit=180`).

### What to send a broadcaster

1. [broadcast-live-feed.md](broadcast-live-feed.md) (as a file or PDF)
2. Their API key, sent privately
3. Match or tournament ids, if useful
4. Later: the WebSocket app key and `wss://` address, once Reverb is live

**Never send:** `REVERB_APP_SECRET`, server or database details, or this file.

---

## Scoring page: Broadcast Feed panel (logged-in scorer, `auth:api`)

Only the match creator or the tournament creator can use these. The feed is **off** for every match until switched on.

| Method | Endpoint | Body | Purpose |
|---|---|---|---|
| GET | `/api/match/{id}/broadcast` | | Status: enabled, mode, current_version, last_published_at |
| POST | `/api/match/{id}/broadcast/enable` | `mode` (auto/manual, optional) | Turn on and publish the current state |
| POST | `/api/match/{id}/broadcast/disable` | | Turn off. The last version stays readable. |
| POST | `/api/match/{id}/broadcast/mode` | `mode` | Switch between Auto and Manual |
| POST | `/api/match/{id}/broadcast/publish` | | Push now (Manual) or Re-publish (both modes) |

**Auto mode** publishes after every change to the match: raid save, edit, undo or skip, technical points, cards, substitutions, line-up and status changes. The publish runs after the scorer's response has been sent, so scoring is not slowed down. Several changes in one request become one version.

---

## How it works

```
Scorer saves raid -> RaidService (DB) -> response to scorer
                                     \-> (after response) BroadcastPublisher
                                           - lock match_broadcast_states row, version + 1
                                           - LiveFeedBuilder builds the JSON from the DB
                                           - save match_feed_snapshots row, update cache
                                           - MatchFeedUpdated -> Reverb -> subscribed broadcasters
```

| File | Role |
|---|---|
| `app/Services/Broadcast/LiveFeedBuilder.php` | DB to client JSON. Same scoring rules as ScoreboardService. `OUTCOME_CODES` sets raid-outcome-id. |
| `app/Services/Broadcast/BroadcastPublisher.php` | Versioning, snapshot, cache, WebSocket push |
| `app/Events/MatchFeedUpdated.php` | WebSocket event |
| `app/Http/Controllers/Broadcast/BroadcastControlController.php` | Scoring panel |
| `app/Http/Controllers/Broadcast/PartnerFeedController.php` | Broadcaster API |
| `app/Console/Commands/CreateBroadcastPartner.php` | Broadcaster and key management |

If Reverb is down, publishing still saves the snapshot (the error is logged), and broadcasters can fetch it over REST.

### Open points

- **Raid outcome codes:** the code follows the client's sample data (2 = empty, 3 = unsuccessful). The client's written answer said the opposite. Confirm with the client before broadcasters build on it.
- **Draw result:** `result-type: "D"` and the "Drew With" text are a guess; the client's sample only shows a win.
- **Raid edits:** `RaidService::update` recalculates who is on court without revivals or all-out resets, so `player-on-court` can be wrong after an edit.

---

## Reverb setup (on the Reverb server)

### Install (one time)

```bash
composer require laravel/reverb   # brings pusher/pusher-php-server (already in composer.lock)
php artisan migrate               # on statifyy.com use --path, see deployment guide
```

`config/reverb.php` and `config/broadcasting.php` are already in the repo. If `reverb:install` or `install:broadcasting` asks to overwrite them, answer **no**, because ours raise the message size limit to 1 MB.

`.env` (see `.env.example`):

```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<random>
REVERB_APP_KEY=<random>        # public, given to broadcasters
REVERB_APP_SECRET=<random>     # private
REVERB_HOST=ws.statifyy.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_BACKEND_HOST=<Reverb server address>
REVERB_BACKEND_PORT=8080
REVERB_BACKEND_SCHEME=http
```

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
    server_name ws.statifyy.com;
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

---

## WebSocket client sample (add to the broadcaster guide when Reverb is live)

Channel `private-broadcast.match.{matchId}`, event `feed.updated`. The broadcaster authorises the channel with their API key through `POST /api/v1/broadcast/socket-auth`.

```js
import Pusher from 'pusher-js';

const pusher = new Pusher('<REVERB_APP_KEY>', {
  wsHost: 'ws.statifyy.com',
  wsPort: 443,
  wssPort: 443,
  forceTLS: true,
  enabledTransports: ['ws', 'wss'],
  cluster: 'mt1', // required by pusher-js, ignored by Reverb
  channelAuthorization: {
    endpoint: 'https://statifyy.com/api/v1/broadcast/socket-auth',
    headers: { 'X-API-Key': '<broadcaster api key>' },
  },
});

let version = 0;
pusher.subscribe('private-broadcast.match.19').bind('feed.updated', (msg) => {
  if (msg.version <= version) return;   // old or repeated
  version = msg.version;
  render(msg.data);                     // full match data; msg['latest-raid'] = newest raid
});

// On connect and every reconnect, call GET /live once so the screen is current at once.
pusher.connection.bind('connected', () => loadCurrentState());
```

Message: `{ event, match-id, version, latest-raid, data }`, where `data` is the same as the `/live` response.

**Testing with Postman (raw Pusher protocol):** connect to `wss://ws.statifyy.com/app/<REVERB_APP_KEY>?protocol=7&client=js&version=8.4.0`, take `socket_id` from `pusher:connection_established`, POST it with `channel_name` to `/api/v1/broadcast/socket-auth` (X-API-Key), then send `{"event":"pusher:subscribe","data":{"auth":"<auth>","channel":"private-broadcast.match.<id>"}}`. Reply to `pusher:ping` with `{"event":"pusher:pong","data":{}}`.
