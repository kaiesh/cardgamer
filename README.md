# Card Table Platform

A digital card table simulator. No enforced game rules -- players interact with cards, chips, and each other just as they would at a real table. Supports Poker, Rummy, Big 2, Casino War, or any card game your group agrees to play.

## Quick Start (Deployment)

The entire platform deploys with a single command:

```bash
php deploy.php
```

The script interactively prompts for everything it needs:
- Database credentials (creates the database, user, schema)
- Pusher API keys (for realtime sync)
- SMTP settings (for OTP login emails)
- Domain name and SSL setup
- Admin email addresses

### Prerequisites

- **Server**: Ubuntu 22.04+ (tested on DigitalOcean 1GB droplet)
- **PHP 8.2+** with extensions: pdo_mysql, mbstring, curl, json, xml
- **MySQL 8.0+**
- **Apache** with mod_rewrite
- **Composer**
- **Pusher account** (free tier: 200k messages/day, 100 connections) -- [pusher.com](https://pusher.com)
- **SMTP service** for OTP emails (Mailgun, Postmark, etc.)

If any prerequisites are missing, the deploy script offers to install them automatically via apt.

## Architecture

| Layer | Technology |
|---|---|
| Backend | PHP 8.2 (vanilla, no framework), Apache |
| Database | MySQL 8.0 |
| Frontend | HTML5, CSS3, Vanilla JS (SPA) |
| Realtime | Pusher Channels |

All game state is server-authoritative in MySQL. The client renders from server truth. Pusher handles realtime fan-out.

## Directory Structure

```
public/                  # Apache DocumentRoot
  index.php              # SPA entry point
  .htaccess              # URL rewriting
  api/
    router.php           # REST API router
  assets/
    css/                 # Stylesheets
    js/
      app.js             # SPA shell & hash router
      api.js             # Fetch wrapper
      pusher-client.js   # Pusher subscription manager
      store.js           # Pub/sub state store
      views/             # Login, Lobby, Game, Admin, etc.
      components/        # Card, Hand, Zone, Chat, etc.
      lib/               # Drag-drop, gestures, DOM helpers
    img/                 # Card faces, backs, UI assets
    uploads/             # Admin-uploaded skins & logos
src/
  config.php             # DB, Pusher, SMTP, app settings
  admins.php             # Admin email list
  db.php                 # PDO connection singleton
  session.php            # MySQL-backed session handler
  auth.php               # OTP generation & verification
  pusher.php             # Server-side Pusher wrapper
  middleware.php         # Auth checks, CSRF validation
  helpers.php            # UUID, JSON responses, validation
  controllers/           # AuthController, TableController, etc.
  migrations/            # SQL schema files
scripts/
  migrate.php            # Run database migrations
  seed_phrases.php       # Seed default chat phrases
  cleanup_sessions.php   # Cron: purge expired sessions
deploy.php               # One-shot deployment script
```

## Features

### Authentication
- Passwordless OTP login via email
- Sessions stored in MySQL (not filesystem)
- 24-hour rolling session expiry
- Admin access via config-listed email addresses

### Game Table
- Create tables with configurable decks (1-8), jokers, card backs, chips
- Zone Builder: drag-to-draw zones on a visual table canvas
- Per-player zones auto-clone when the game starts
- Templates: save and reuse table layouts

### Card Interactions
- Full card lifecycle: shuffle, deal, flip, peek, reveal, mark, discard, remove
- Gesture-first UX: double-tap to flip, long-press to peek, drag to move
- Context menus for all valid actions
- Card privacy: server never leaks hidden card data

### Realtime
- All actions broadcast instantly via Pusher
- Presence channel for lobby online users
- Private channels for table events and personal notifications
- Reconnect with full state refresh

### Chips
- Integer-based chip system with player balances and pots
- Transfer chips between players and pots
- Append-only transaction ledger

### Chat
- Phrase-based chat (pick from a grid of pre-defined phrases)
- Default phrases + table-creator custom phrases
- Speech bubbles near player seats

### Admin Panel
- Dashboard with stats (users, tables, games)
- Deck skin upload and management
- Table logo upload and management
- Chat phrase management
- User and table listing

### Game History
- Action log replay for closed games
- Filter by player or action type
- Stats summary (card actions, chip transfers, duration)
- Export action log as JSON

## API

All endpoints under `/api/v1/`. JSON request/response. Session cookie authentication.

### Key Endpoints

| Method | Path | Description |
|---|---|---|
| POST | `/auth/request-otp` | Send OTP email |
| POST | `/auth/verify-otp` | Verify OTP, start session |
| GET | `/lobby/tables` | List open tables |
| POST | `/tables` | Create a table |
| POST | `/tables/{id}/start` | Start the game |
| GET | `/tables/{id}/state` | Full table state (filtered per player) |
| POST | `/tables/{id}/cards/action` | Unified card action endpoint |
| POST | `/tables/{id}/chips/transfer` | Transfer chips |
| POST | `/tables/{id}/chat` | Send a chat phrase |

The card action endpoint uses `{ "action": "<type>", ... }` to handle all card interactions through a single endpoint. See the spec for the full list of 25+ action types.

## Configuration

### config.php

Generated by the deploy script. Contains:
- Database connection (host, port, name, user, password)
- Pusher credentials (app_id, key, secret, cluster)
- SMTP settings (host, port, user, password, from address)
- App settings (name, URL, session TTL, OTP TTL, rate limits)

### admins.php

Array of email addresses that get admin privileges:
```php
return ['admin@example.com'];
```

## Maintenance

### Session Cleanup

A cron job runs every 5 minutes to purge expired sessions:
```
*/5 * * * * php /var/www/cardtable/scripts/cleanup_sessions.php
```

This is set up automatically by the deploy script.

### Database Migrations

To apply new schema changes:
```bash
php scripts/migrate.php
```

### Updating

Pull latest code and re-run migrations:
```bash
cd /var/www/cardtable
git pull
composer install --no-dev
php scripts/migrate.php
sudo systemctl reload apache2
```

## Scaling

The architecture is designed for horizontal scaling:
- All state in MySQL (no in-memory state on web servers)
- Stateless Apache/PHP processes
- Pusher handles realtime fan-out off-server
- Add droplets behind a load balancer, all pointing at a managed MySQL instance

## Security

- Server never sends hidden card suit/rank data to unauthorized players
- All SQL uses prepared statements (PDO)
- OTP rate limiting (5 per email per 15 minutes)
- Session cookies: HttpOnly, Secure, SameSite=Strict
- CSRF protection via X-Requested-With header
- File upload validation (type, size)
- No CORS headers (same-origin only)

## License

Private project.
