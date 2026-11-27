# Kabaddi Backend – Deployment Addendum: Broadcaster Live Feed

**Use together with the main Deployment Guide.** This release adds a live WebSocket feed for broadcasters (Laravel Reverb). The main guide's steps still apply. This addendum lists the extra steps and one exception.

| Who | Steps |
|---|---|
| Developer / deployer | A1–A6 |
| Server administrator | B1–B3 (process manager, web server, SSL, firewall) |
| Domain owner | DNS record (B2) |

---

## Before you start

1. **Back up the production database** and the current `.env`:
   ```bash
   mysqldump -u <db_user> -p <db_name> > ~/backup-$(date +%F-%H%M).sql
   cp .env ~/env-backup-$(date +%F-%H%M)
   ```
2. **Choose the WebSocket address**, for example `ws.your-domain.com`. Broadcasters connect to `wss://ws.your-domain.com`.
3. Note the current commit for rollback: `git rev-parse HEAD`.

---

## Part A – Application (developer / deployer)

### A1 – Update the code

Deploy the branch that contains this feature. This is `develop` once the pull request is merged (`feature/broadcast-live-feed` until then).

```bash
cd /path/to/kabaddi-backend
git fetch origin
git checkout develop && git pull origin develop
```

### A2 – Install dependencies (main guide, Step 2)

```bash
composer install --no-dev --optimize-autoloader
```

This installs the new packages `laravel/reverb` and `pusher/pusher-php-server` from `composer.lock`.

### A3 – Run the new migration (**exception to main guide, Step 6**)

The main guide says not to run migrations on an existing installation. **This release needs it once**, to create 4 new tables:

```bash
php artisan migrate --force
```

- This only creates tables that don't exist yet: `broadcast_partners`, `broadcast_partner_access`, `match_broadcast_states`, `match_feed_snapshots`. Existing data is not changed.
- Check first with `php artisan migrate:status`. Only `2026_11_27_000001_create_broadcast_tables` should show as pending.
- **If many old migrations show as "Pending"** even though the tables exist (as on statifyy.com, where the database was imported without migration history), **do not run a plain `migrate`**. Run only this migration:
  ```bash
  php artisan migrate --force --path=database/migrations/2026_11_27_000001_create_broadcast_tables.php
  ```
- **Never** run `migrate:fresh` or `migrate:refresh`.

> **Hosting note (statifyy.com):** production is on Hostinger **shared** hosting (no sudo or Supervisor), which can't run Reverb. Part A works there; Part B needs a separate small VPS that runs only Reverb, with `REVERB_BACKEND_*` and `REVERB_HOST` pointing at it. Until then keep `BROADCAST_CONNECTION=null`. The REST feed (`/live`) and the scoring panel still work.
>
> **Backups on shared hosting:** `mysqldump` must use the local socket (`--protocol=socket --socket=/var/lib/mysql/mysql.sock`), because the database user is not allowed over TCP. The server's `.env` has Windows (CRLF) line endings, so strip `\r` when reading values in scripts.

### A4 – Add Reverb settings to `.env`

Generate new values on the server. Don't reuse local test values such as `localkey`.

```bash
echo "REVERB_APP_ID=$(shuf -i 100000-999999 -n 1)"
echo "REVERB_APP_KEY=$(openssl rand -hex 16)"
echo "REVERB_APP_SECRET=$(openssl rand -hex 32)"
```

Add to `.env` and change `BROADCAST_CONNECTION`:

```
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=<generated>
REVERB_APP_KEY=<generated>          # public: given to broadcasters
REVERB_APP_SECRET=<generated>       # private: never share

# Public address broadcasters connect to
REVERB_HOST=ws.your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https

# Where the backend sends events (local Reverb process, bypasses the proxy)
REVERB_BACKEND_HOST=127.0.0.1
REVERB_BACKEND_PORT=8080
REVERB_BACKEND_SCHEME=http

# Reverb listens only on localhost; the web server proxies wss:// to it
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
```

Leave `APP_KEY`, `DB_*` and the Passport keys unchanged (main guide, Notes).

### A5 – Clear and cache config (main guide, Step 7)

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run this again whenever `.env` changes.

### A6 – Restart Reverb after every deployment

Once Supervisor is set up (B1):

```bash
php artisan reverb:restart     # Supervisor starts it again with the new code
```

---

## Part B – Server (server administrator)

### B1 – Keep Reverb running (Supervisor)

```bash
sudo apt install -y supervisor
sudo nano /etc/supervisor/conf.d/kabaddi-reverb.conf
```

```ini
[program:kabaddi-reverb]
command=php /path/to/kabaddi-backend/artisan reverb:start
directory=/path/to/kabaddi-backend
user=www-data
autostart=true
autorestart=true
stopwaitsecs=10
minfds=10000
stdout_logfile=/path/to/kabaddi-backend/storage/logs/reverb.log
redirect_stderr=true
```

Use the same `php` binary and user as the website (check with `which php` and the web server's user).

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status kabaddi-reverb      # should say RUNNING
ss -ltnp | grep 8080                          # should show php on 127.0.0.1:8080
```

### B2 – DNS, web server proxy and SSL

1. **DNS:** add an **A record** `ws.your-domain.com` → VPS IP address.
2. **Web server:** use the block for the server you run.

**Nginx:**

```nginx
server {
    listen 80;
    server_name ws.your-domain.com;

    location / {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Scheme $scheme;
        proxy_set_header SERVER_PORT $server_port;
        proxy_set_header REMOTE_ADDR $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_read_timeout 120s;
        proxy_pass http://127.0.0.1:8080;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

**Apache:** enable the modules first with `sudo a2enmod proxy proxy_http proxy_wstunnel rewrite`.

```apache
<VirtualHost *:80>
    ServerName ws.your-domain.com
    ProxyPreserveHost On
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule /(.*) ws://127.0.0.1:8080/$1 [P,L]
    ProxyPass / http://127.0.0.1:8080/
    ProxyPassReverse / http://127.0.0.1:8080/
</VirtualHost>
```

```bash
sudo apachectl configtest && sudo systemctl reload apache2
```

3. **SSL:**

```bash
sudo certbot --nginx -d ws.your-domain.com     # or: --apache
```

### B3 – Firewall

- Open: 443 (and 80 for the certificate).
- **Do not** open 8080 publicly. Reverb listens on 127.0.0.1 only.

---

## Verify

```bash
# 1. Reverb is running
sudo supervisorctl status kabaddi-reverb

# 2. WebSocket answers over SSL (expect "101 Switching Protocols")
curl -i -N --http1.1 \
  -H "Connection: Upgrade" -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGVzdGtleTEyMzQ1Njc4OQ==" \
  "https://ws.your-domain.com/app/<REVERB_APP_KEY>?protocol=7&client=js&version=8.4.0"

# 3. Create a test broadcaster for one match (prints the API key once)
php artisan broadcast:partner "Internal Test" --match=<matchId>

# 4. Partner REST API responds
curl -H "X-API-Key: <key>" https://your-domain.com/api/v1/broadcast/matches
```

Then switch on the feed for a match from the scoring panel:
`POST /api/match/{id}/broadcast/enable` with the scorer's token. Record a raid and check that the test client receives `feed.updated`. See `docs/broadcast-live-feed.md`.

Finally, deactivate the test broadcaster: `php artisan broadcast:partner --id=<id> --deactivate`.

---

## Rollback

The feed is **off for every match** until someone enables it, so normal scoring is not affected by this release.

If something goes wrong:

```bash
git checkout <previous-commit>
composer install --no-dev --optimize-autoloader
sed -i 's/^BROADCAST_CONNECTION=reverb/BROADCAST_CONNECTION=log/' .env
php artisan optimize:clear && php artisan config:cache && php artisan route:cache
sudo supervisorctl stop kabaddi-reverb
```

The 4 new tables can stay; nothing else uses them. Restore the database backup only if data was damaged, which this release does not do.

---

## Troubleshooting

| Symptom | Check |
|---|---|
| Postman/client "Socket connection error" | Supervisor status; `ss -ltnp \| grep 8080`; DNS; certificate |
| Connects but no updates arrive | `BROADCAST_CONNECTION=reverb`; `REVERB_BACKEND_*` points at 127.0.0.1:8080; ran `config:cache` after editing `.env`; `storage/logs/laravel.log` |
| `socket-auth` returns 403 | Broadcaster has no access to that match: `php artisan broadcast:partner --id=<id> --match=<matchId>` |
| Messages larger than 1 MB rejected | `REVERB_APP_MAX_MESSAGE_SIZE` / `REVERB_MAX_REQUEST_SIZE` in `.env` (default 1 MB) |
| Old code still running after deploy | `php artisan reverb:restart` |
