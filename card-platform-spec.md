# Technical Specification: Open Card Table Platform

## 1. Project Overview

### 1.1 Vision
A **digital card table simulator** — not a specific card game engine. The platform replicates the experience of sitting at a physical card table with a real deck of cards. There are no enforced game rules; players interact with cards, chips, and each other just as they would in person. The platform is game-agnostic and supports Poker, Rummy, Big 2, Casino War, or any card game the players agree to play.

### 1.2 Core Principles
- **Freeform play**: No turn enforcement. Players act when they choose, just like a real table.
- **Contextual interaction**: Available actions depend on what the player is touching/holding and where.
- **Realtime sync**: Every action is broadcast instantly to all participants via Pusher.
- **Server-authoritative state**: All game state lives in MySQL. The client renders from server truth.
- **Action transparency**: A persistent action feed logs every move, visible to all players at the table.
- **Save & resume**: Tables can be paused mid-game and restored later.

### 1.3 Tech Stack
| Layer | Technology |
|---|---|
| Backend | PHP 8.2+ (vanilla, no framework), Apache with mod_rewrite |
| Database | MySQL 8.0 |
| Frontend | HTML5, CSS3, Vanilla JS (Single Page App) |
| Realtime | Pusher Channels |
| Hosting | DigitalOcean Droplet (1GB RAM initial) |
| SSL | Let's Encrypt via Certbot |
| Mail | SMTP service (e.g. Mailgun, Postmark, or DigitalOcean SMTP) for OTP delivery |

### 1.4 Scaling Strategy
- All state is in MySQL — no in-memory state on the web server.
- Apache processes are stateless; any request can be handled by any server.
- Horizontal scaling: add droplets behind a DigitalOcean Load Balancer, all pointing at a single managed MySQL instance.
- Pusher handles the realtime fan-out off-server.
- Session data stored in MySQL (not filesystem).

---

## 2. Authentication & Sessions

### 2.1 Flow
1. User enters their email address on the login screen.
2. Server generates a 6-digit numeric OTP, stores it hashed (`password_hash`) in `otp_codes` table with a 10-minute expiry.
3. OTP is sent to the email via SMTP.
4. User enters the OTP on the verification screen.
5. Server validates the OTP, creates or retrieves the user record, and issues a session.
6. If the email does not exist in `users`, a new user record is created and the user is prompted to choose a display name.

### 2.2 Session Management
- Sessions are stored in MySQL (`sessions` table), not PHP's default file-based sessions.
- A custom session handler class (`DbSessionHandler`) implements `SessionHandlerInterface`.
- Session cookie: `HttpOnly`, `Secure`, `SameSite=Strict`.
- Session lifetime: **24 hours of inactivity** (rolling expiry). Each authenticated request refreshes the expiry.
- A cron job runs hourly to purge expired sessions.

### 2.3 Admin Access
- A config file (`config/admins.php`) contains an array of admin email addresses.
- Admin login uses the same OTP flow.
- Upon successful OTP verification, if the email is in the admin list, an `is_admin` flag is set on the session.
- All admin API endpoints check this flag.

---

## 3. Database Schema

### 3.1 Entity-Relationship Overview

```
users ──< table_players >── tables
                               │
                               ├──< zones
                               ├──< cards
                               ├──< chips
                               ├──< action_log
                               └──< table_chat
                               
users ──< templates ──< template_zones
users ──< sessions
       ──< otp_codes

deck_skins (admin-managed)
table_logos (admin-managed)
chat_phrases
```

### 3.2 Table Definitions

```sql
-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE users (
    id              CHAR(36) PRIMARY KEY,           -- UUIDv4
    email           VARCHAR(255) NOT NULL UNIQUE,
    display_name    VARCHAR(50) NULL,
    avatar_color    VARCHAR(7) DEFAULT '#3B82F6',   -- Hex color for default avatar
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE otp_codes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255) NOT NULL,
    otp_hash        VARCHAR(255) NOT NULL,           -- password_hash output
    expires_at      DATETIME NOT NULL,
    used            TINYINT(1) DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_expires (email, expires_at)
) ENGINE=InnoDB;

CREATE TABLE sessions (
    id              VARCHAR(128) PRIMARY KEY,
    user_id         CHAR(36) NULL,
    data            MEDIUMTEXT NOT NULL,
    last_activity   INT UNSIGNED NOT NULL,
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB;

-- ============================================================
-- TABLES (GAME TABLES)
-- ============================================================
CREATE TABLE tables (
    id              CHAR(36) PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    creator_id      CHAR(36) NOT NULL,
    status          ENUM('lobby','active','paused','closed') DEFAULT 'lobby',
    num_decks       TINYINT UNSIGNED DEFAULT 1,
    include_jokers  TINYINT(1) DEFAULT 0,
    deck_backs      ENUM('uniform','random_per_deck') DEFAULT 'uniform',
    deck_skin_id    INT UNSIGNED NULL,               -- FK to deck_skins (branded back)
    table_logo_id   INT UNSIGNED NULL,               -- FK to table_logos
    chip_initial    INT UNSIGNED DEFAULT 0,           -- Starting chips per player (0 = no chips)
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    paused_at       DATETIME NULL,
    FOREIGN KEY (creator_id) REFERENCES users(id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE table_players (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    user_id         CHAR(36) NOT NULL,
    seat_index      TINYINT UNSIGNED NOT NULL,       -- Position around the table
    is_connected    TINYINT(1) DEFAULT 1,
    chips           INT UNSIGNED DEFAULT 0,           -- Current chip count in hand
    joined_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_table_user (table_id, user_id),
    UNIQUE KEY uk_table_seat (table_id, seat_index),
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================
-- ZONES
-- ============================================================
CREATE TABLE zones (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    label           VARCHAR(50) NOT NULL,            -- e.g. "Common Discard", "Draw Pile"
    zone_type       ENUM('shared','per_player') NOT NULL,
    owner_player_id INT UNSIGNED NULL,               -- FK to table_players.id; NULL for shared zones
    layout_mode     ENUM('stacked','spread') DEFAULT 'stacked',
    flip_visibility ENUM('private','public') DEFAULT 'private',
        -- 'private' = when a player peeks/flips, only they see the face
        -- 'public'  = when a player flips, everyone sees the face
    pos_x           FLOAT NOT NULL,                  -- Canvas position (percentage 0-100)
    pos_y           FLOAT NOT NULL,
    width           FLOAT NOT NULL,                  -- Canvas size (percentage)
    height          FLOAT NOT NULL,
    color           VARCHAR(7) DEFAULT '#1E3A5F',    -- Zone background color
    z_order         SMALLINT UNSIGNED DEFAULT 0,
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_player_id) REFERENCES table_players(id) ON DELETE SET NULL,
    INDEX idx_table (table_id)
) ENGINE=InnoDB;

-- ============================================================
-- CARDS
-- ============================================================
CREATE TABLE cards (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    deck_index      TINYINT UNSIGNED NOT NULL,       -- Which deck (0-based)
    suit            ENUM('hearts','diamonds','clubs','spades','joker') NOT NULL,
    rank            VARCHAR(5) NOT NULL,             -- 'A','2'..'10','J','Q','K','joker'
    face_up         TINYINT(1) DEFAULT 0,
    zone_id         INT UNSIGNED NULL,               -- Current zone (NULL = in a player's hand)
    holder_player_id INT UNSIGNED NULL,              -- FK to table_players.id (NULL = not in hand)
    position_in_zone INT UNSIGNED DEFAULT 0,         -- Sort order within zone/hand
    peeked_by       JSON DEFAULT NULL,               -- Array of user_ids currently peeking
    marked_by       JSON DEFAULT NULL,               -- Array of user_ids who secretly marked it
    in_play         TINYINT(1) DEFAULT 1,            -- 0 = banished/removed from play
    back_skin_id    INT UNSIGNED NULL,               -- Override per-card back (for random_per_deck)
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
    FOREIGN KEY (holder_player_id) REFERENCES table_players(id) ON DELETE SET NULL,
    INDEX idx_table_zone (table_id, zone_id),
    INDEX idx_table_holder (table_id, holder_player_id)
) ENGINE=InnoDB;

-- ============================================================
-- CHIPS
-- ============================================================
CREATE TABLE chip_pots (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    label           VARCHAR(50) NOT NULL,            -- "Main Pot", "Side Pot", "Player 1 Bet"
    zone_id         INT UNSIGNED NULL,               -- Optionally tied to a zone
    owner_player_id INT UNSIGNED NULL,               -- NULL = communal pot
    amount          INT UNSIGNED DEFAULT 0,
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
    FOREIGN KEY (owner_player_id) REFERENCES table_players(id) ON DELETE SET NULL,
    INDEX idx_table (table_id)
) ENGINE=InnoDB;

CREATE TABLE chip_transactions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    from_type       ENUM('player','pot') NOT NULL,
    from_id         INT UNSIGNED NOT NULL,            -- table_players.id or chip_pots.id
    to_type         ENUM('player','pot') NOT NULL,
    to_id           INT UNSIGNED NOT NULL,
    amount          INT UNSIGNED NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    INDEX idx_table_time (table_id, created_at)
) ENGINE=InnoDB;

-- ============================================================
-- ACTION LOG (the action feed)
-- ============================================================
CREATE TABLE action_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    user_id         CHAR(36) NOT NULL,
    action_type     VARCHAR(50) NOT NULL,            -- See §5.2 for enum of action types
    payload         JSON NOT NULL,                   -- Action-specific data
    created_at      DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),  -- Millisecond precision
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    INDEX idx_table_time (table_id, created_at)
) ENGINE=InnoDB;

-- ============================================================
-- CHAT
-- ============================================================
CREATE TABLE chat_phrases (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phrase          VARCHAR(200) NOT NULL,
    is_default      TINYINT(1) DEFAULT 1,            -- Default phrases available everywhere
    table_id        CHAR(36) NULL,                   -- NULL = global; set = table-specific custom
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    INDEX idx_table (table_id)
) ENGINE=InnoDB;

CREATE TABLE table_chat (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    user_id         CHAR(36) NOT NULL,
    phrase_id       INT UNSIGNED NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    FOREIGN KEY (phrase_id) REFERENCES chat_phrases(id),
    INDEX idx_table_time (table_id, created_at)
) ENGINE=InnoDB;

-- ============================================================
-- TEMPLATES
-- ============================================================
CREATE TABLE templates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    creator_id      CHAR(36) NOT NULL,
    name            VARCHAR(100) NOT NULL,
    is_public       TINYINT(1) DEFAULT 0,
    num_decks       TINYINT UNSIGNED DEFAULT 1,
    include_jokers  TINYINT(1) DEFAULT 0,
    deck_backs      ENUM('uniform','random_per_deck') DEFAULT 'uniform',
    chip_initial    INT UNSIGNED DEFAULT 0,
    custom_phrases  JSON DEFAULT NULL,               -- Array of custom phrase strings
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creator_id) REFERENCES users(id),
    INDEX idx_public (is_public),
    INDEX idx_creator (creator_id)
) ENGINE=InnoDB;

CREATE TABLE template_zones (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id     INT UNSIGNED NOT NULL,
    label           VARCHAR(50) NOT NULL,
    zone_type       ENUM('shared','per_player') NOT NULL,
    layout_mode     ENUM('stacked','spread') DEFAULT 'stacked',
    flip_visibility ENUM('private','public') DEFAULT 'private',
    pos_x           FLOAT NOT NULL,
    pos_y           FLOAT NOT NULL,
    width           FLOAT NOT NULL,
    height          FLOAT NOT NULL,
    color           VARCHAR(7) DEFAULT '#1E3A5F',
    z_order         SMALLINT UNSIGNED DEFAULT 0,
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
    INDEX idx_template (template_id)
) ENGINE=InnoDB;

-- ============================================================
-- ADMIN: DECK SKINS & TABLE LOGOS
-- ============================================================
CREATE TABLE deck_skins (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,           -- "Bacardi", "Hilton", "Classic Red"
    back_image_path VARCHAR(255) NOT NULL,           -- Path to uploaded image
    is_active       TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE table_logos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    image_path      VARCHAR(255) NOT NULL,
    is_active       TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

### 3.3 Key Design Decisions
- **UUIDs for users and tables** to avoid sequential ID leaking, and for future sharding.
- **`zone_id` + `holder_player_id` on cards**: A card is in exactly one location. If `holder_player_id` is set, the card is in that player's hand. If `zone_id` is set, the card is in that zone on the table. Both NULL = limbo (should not occur).
- **`peeked_by` as JSON array**: Avoids a junction table for a transient state. Contains user IDs of players currently peeking at this card.
- **`per_player` zones**: When `zone_type = 'per_player'`, the system clones the zone definition once per seated player at game start, setting `owner_player_id` for each clone.
- **Chip transactions** are an append-only ledger for auditability.

---

## 4. Directory Structure

```
/var/www/cardtable/
├── public/                          # Apache DocumentRoot
│   ├── index.php                    # SPA entry point (serves HTML shell)
│   ├── .htaccess                    # Rewrites all non-asset requests to index.php
│   ├── api/
│   │   ├── .htaccess                # Rewrites to router.php
│   │   └── router.php               # API request router
│   └── assets/
│       ├── css/
│       │   ├── app.css              # Main stylesheet
│       │   ├── table.css            # Game table & card styles
│       │   └── zone-builder.css     # Zone drawing canvas styles
│       ├── js/
│       │   ├── app.js               # SPA shell, router, auth state
│       │   ├── api.js               # API client (fetch wrapper)
│       │   ├── pusher-client.js     # Pusher subscription manager
│       │   ├── views/
│       │   │   ├── login.js
│       │   │   ├── lobby.js
│       │   │   ├── table-create.js
│       │   │   ├── zone-builder.js  # Drag-to-draw zone canvas
│       │   │   ├── game.js          # Main game table view
│       │   │   ├── templates.js
│       │   │   ├── history.js       # Past game review
│       │   │   └── admin.js         # Admin panel
│       │   ├── components/
│       │   │   ├── card.js          # Card rendering & interaction
│       │   │   ├── hand.js          # Player's hand (drag to reorder)
│       │   │   ├── zone.js          # Zone rendering
│       │   │   ├── chip-tray.js     # Chip display & transfer UI
│       │   │   ├── action-feed.js   # Scrolling action log
│       │   │   ├── chat.js          # Phrase-picker chat
│       │   │   ├── context-menu.js  # Contextual action menu
│       │   │   └── player-seat.js   # Player avatar, hand back display
│       │   └── lib/
│       │       ├── drag.js          # Unified drag & drop (touch + mouse)
│       │       ├── gestures.js      # Double-tap, long-press detection
│       │       └── render.js        # DOM helpers
│       ├── img/
│       │   ├── cards/               # Card face SVGs (standard deck)
│       │   ├── backs/               # Default card back images
│       │   └── ui/                  # Icons, empty states
│       └── uploads/                 # Admin-uploaded deck skins & logos
├── src/
│   ├── config.php                   # DB credentials, Pusher keys, app constants
│   ├── admins.php                   # Array of admin emails
│   ├── db.php                       # PDO singleton / connection factory
│   ├── session.php                  # DbSessionHandler class
│   ├── auth.php                     # OTP generation, verification, session bootstrap
│   ├── pusher.php                   # Pusher server-side SDK wrapper
│   ├── middleware.php               # Auth check, admin check, JSON parsing
│   ├── helpers.php                  # UUID generation, response formatting, validation
│   ├── controllers/
│   │   ├── AuthController.php
│   │   ├── LobbyController.php
│   │   ├── TableController.php
│   │   ├── ZoneController.php
│   │   ├── CardController.php
│   │   ├── ChipController.php
│   │   ├── ChatController.php
│   │   ├── TemplateController.php
│   │   ├── ActionLogController.php
│   │   ├── AdminController.php
│   │   └── PusherAuthController.php
│   └── migrations/
│       ├── 001_create_users.sql
│       ├── 002_create_tables.sql
│       └── ...                      # One file per schema change
├── vendor/                          # Composer packages (Pusher SDK)
├── composer.json
└── scripts/
    ├── migrate.php                  # Runs pending migrations
    └── seed_phrases.php             # Seeds default chat phrases
```

---

## 5. REST API

### 5.1 Conventions
- Base path: `/api/v1/`
- All request/response bodies are JSON.
- Authentication: session cookie (set via OTP login).
- Error responses: `{ "error": "Human-readable message", "code": "MACHINE_CODE" }`
- Success responses: `{ "data": { ... } }`
- HTTP methods: `GET` (read), `POST` (create/action), `PUT` (update), `DELETE` (remove).

### 5.2 Endpoints

#### Authentication
| Method | Path | Description |
|---|---|---|
| POST | `/auth/request-otp` | `{ "email": "..." }` → sends OTP |
| POST | `/auth/verify-otp` | `{ "email": "...", "otp": "123456" }` → sets session cookie, returns user |
| POST | `/auth/logout` | Destroys session |
| GET | `/auth/me` | Returns current user or 401 |
| PUT | `/auth/me` | Update display name, avatar color |

#### Lobby
| Method | Path | Description |
|---|---|---|
| GET | `/lobby/tables` | List tables with `status=lobby` (waiting for players) |
| GET | `/lobby/tables/{id}` | Table detail with players list |
| GET | `/lobby/online-users` | List users currently online (have active sessions) |

#### Tables
| Method | Path | Description |
|---|---|---|
| POST | `/tables` | Create a table. Body includes name, deck config, chip config. Returns table ID. |
| POST | `/tables/{id}/join` | Join a table (picks next seat). |
| POST | `/tables/{id}/leave` | Leave a table voluntarily. |
| POST | `/tables/{id}/kick` | Creator only. `{ "user_id": "..." }` |
| POST | `/tables/{id}/start` | Creator only. Transitions from `lobby` → `active`. Generates card records for all decks. Clones `per_player` zones. |
| POST | `/tables/{id}/pause` | Creator only. `active` → `paused`. |
| POST | `/tables/{id}/resume` | Creator only. `paused` → `active`. |
| POST | `/tables/{id}/close` | Creator only. `active`/`paused` → `closed`. |
| GET | `/tables/{id}/state` | Full table state snapshot (zones, cards, chips, players). Used on initial load and reconnect. |
| GET | `/tables/{id}/actions` | Paginated action log. `?before={id}&limit=50` |

#### Zones (pre-game only, during `lobby` status)
| Method | Path | Description |
|---|---|---|
| POST | `/tables/{id}/zones` | Creator only. Add a zone. |
| PUT | `/tables/{id}/zones/{zoneId}` | Creator only. Update zone properties. |
| DELETE | `/tables/{id}/zones/{zoneId}` | Creator only. Remove a zone. |
| GET | `/tables/{id}/zones` | List all zones for the table. |

#### Card Actions (during `active` status)
All card action endpoints follow the pattern:
```
POST /tables/{tableId}/cards/action
Body: { "action": "<action_type>", "card_ids": [...], ...params }
```

A **single unified endpoint** handles all card interactions. The `action` field determines behavior, and additional fields supply context. This keeps the API surface small and the frontend simple.

**Action types and their parameters:**

```
DECK ACTIONS (actor must hold/have access to deck zone):
  shuffle          { zone_id }                          — Randomize card order in zone
  cut              { zone_id, position }                — Split deck at position
  count            { zone_id }                          — Returns count (read-only, no state change)

CARD MOVEMENT:
  deal             { card_ids, target_player_id }       — Move top N cards from zone to player hand
  deal_to_zone     { card_ids, target_zone_id }         — Move cards to a zone (e.g. community)
  deal_to_self     { count, source_zone_id }            — Take N cards from zone into own hand
  take_from_zone   { card_ids, source_zone_id }         — Pick specific cards from a zone
  place_in_zone    { card_ids, target_zone_id, position: 'top'|'bottom'|index }
  give_to_player   { card_ids, target_player_id }       — Hand → another player's hand
  return_to_zone   { card_ids, target_zone_id }         — Hand → zone
  swap_with_player { card_id, target_player_id, target_card_id }
  force_give       { card_ids, target_player_id }       — Push card to player (no acceptance needed)
  force_take       { card_ids, target_player_id }       — Pull card from player
  offer            { card_ids, target_player_id }       — Creates pending offer (target must accept)
  accept_offer     { offer_id }
  decline_offer    { offer_id }
  discard          { card_ids, target_zone_id }         — Hand → discard zone

CARD STATE:
  flip             { card_ids }                         — Toggle face_up/face_down
  peek             { card_ids }                         — View card face without revealing
  unpeek           { card_ids }                         — Stop peeking
  reveal           { card_ids }                         — Force face_up, visible to all
  mark             { card_ids }                         — Secretly mark (only marker knows)
  unmark           { card_ids }
  remove_from_play { card_ids }                         — Banish from game
  return_to_play   { card_ids, target_zone_id }         — Unbanish
  reorder_hand     { card_ids_ordered }                 — Reorder cards in own hand

CARD INSPECTION (read-only, no mutation):
  inspect_back     { card_id }                          — Returns back skin info
  identify         { card_id }                          — Returns suit + rank (if visible to actor)
```

#### Chip Actions
| Method | Path | Description |
|---|---|---|
| POST | `/tables/{id}/chips/transfer` | `{ "from_type", "from_id", "to_type", "to_id", "amount" }` |
| POST | `/tables/{id}/chips/create-pot` | `{ "label", "zone_id?" }` — Creator or any player creates a pot |
| GET | `/tables/{id}/chips` | All chip state (player balances + pots) |

#### Chat
| Method | Path | Description |
|---|---|---|
| POST | `/tables/{id}/chat` | `{ "phrase_id": 12 }` — Send a phrase |
| GET | `/tables/{id}/chat` | Paginated chat log |
| GET | `/tables/{id}/chat/phrases` | Available phrases (defaults + table customs) |
| POST | `/tables/{id}/chat/phrases` | Creator only. Add custom phrase. |
| DELETE | `/tables/{id}/chat/phrases/{phraseId}` | Creator only. Remove custom phrase. |

#### Templates
| Method | Path | Description |
|---|---|---|
| GET | `/templates` | List own + public templates |
| GET | `/templates/{id}` | Template detail with zones |
| POST | `/templates` | Create from scratch or from current table |
| PUT | `/templates/{id}` | Update (own templates only) |
| DELETE | `/templates/{id}` | Delete (own templates only) |
| POST | `/tables/{id}/apply-template` | Creator only, pre-game only. Apply template zones to table. |

#### Admin
| Method | Path | Description |
|---|---|---|
| GET | `/admin/deck-skins` | List all skins |
| POST | `/admin/deck-skins` | Upload new skin (multipart form) |
| PUT | `/admin/deck-skins/{id}` | Update name, toggle active |
| DELETE | `/admin/deck-skins/{id}` | Remove skin |
| GET | `/admin/table-logos` | List all logos |
| POST | `/admin/table-logos` | Upload new logo |
| PUT | `/admin/table-logos/{id}` | Update |
| DELETE | `/admin/table-logos/{id}` | Remove |
| GET | `/admin/chat-phrases` | List all default phrases |
| POST | `/admin/chat-phrases` | Add phrase |
| DELETE | `/admin/chat-phrases/{id}` | Remove phrase |
| GET | `/admin/tables` | List all tables (any status), with player counts |
| GET | `/admin/users` | List all users with last activity |
| GET | `/admin/stats` | Dashboard: total users, active tables, etc. |

#### Pusher Auth
| Method | Path | Description |
|---|---|---|
| POST | `/pusher/auth` | Pusher webhook to authenticate private/presence channel subscriptions. Validates session and table membership. |

---

## 6. Pusher Realtime Events

### 6.1 Channel Strategy
| Channel | Type | Purpose |
|---|---|---|
| `presence-lobby` | Presence | Who is online in the lobby. Members list = online users. |
| `private-table-{tableId}` | Private | All game events for a specific table. |
| `private-user-{userId}` | Private | Personal notifications (offers, kicks, invites). |

### 6.2 Events on `private-table-{tableId}`

All events carry a standardized envelope:
```json
{
  "actor": { "id": "uuid", "name": "Alice" },
  "action": "card.flip",
  "payload": { ... },
  "timestamp": "2027-01-15T10:30:00.123Z",
  "action_log_id": 12345
}
```

**Event list:**

```
PLAYER EVENTS:
  player.joined          { player }
  player.left            { player }
  player.kicked          { player, by }
  player.connected       { player }
  player.disconnected    { player }

GAME LIFECYCLE:
  game.started           { }
  game.paused            { }
  game.resumed           { }
  game.closed            { }

CARD EVENTS:
  card.shuffled          { zone_id }
  card.cut               { zone_id, position }
  card.dealt             { card_ids, target_player_id, source_zone_id }
  card.dealt_to_zone     { card_ids, target_zone_id }
  card.taken             { card_ids, source_zone_id, player_id }
  card.placed            { card_ids, target_zone_id, position }
  card.given             { card_ids, from_player_id, to_player_id }
  card.flipped           { card_ids, face_up, zone_id? }
  card.peeked            { card_ids, player_id }          — Others see "player is peeking"
  card.unpeeked          { card_ids, player_id }
  card.revealed          { card_ids, suit, rank }          — Full card info sent
  card.marked            { card_ids }                      — No detail (it's secret)
  card.removed           { card_ids }
  card.returned          { card_ids, target_zone_id }
  card.swapped           { card_id_a, card_id_b, player_a, player_b }
  card.offered           { offer_id, card_ids, from, to }
  card.offer_accepted    { offer_id }
  card.offer_declined    { offer_id }
  card.reordered         { player_id, count }              — No card detail (hand is private)
  card.discarded         { card_ids, zone_id }
  card.forced_give       { card_ids, from, to }
  card.forced_take       { card_ids, from, to }

CHIP EVENTS:
  chips.transferred      { from_type, from_id, to_type, to_id, amount }
  chips.pot_created      { pot }

CHAT EVENTS:
  chat.message           { user_id, phrase_id, phrase_text }

ZONE EVENTS (pre-game only):
  zone.created           { zone }
  zone.updated           { zone }
  zone.deleted           { zone_id }
```

### 6.3 Sensitive Data Handling

**Critical**: The server must **never** broadcast private card information to unauthorized players.

When a card is face-down and in a player's hand, other players receive:
```json
{
  "card_id": 42,
  "face_up": false,
  "suit": null,
  "rank": null,
  "back_skin": "classic_red",
  "holder_player_id": 7
}
```

When a card is face-up or the requesting player is peeking:
```json
{
  "card_id": 42,
  "face_up": true,
  "suit": "hearts",
  "rank": "A",
  "back_skin": "classic_red",
  "holder_player_id": 7
}
```

The `/tables/{id}/state` endpoint also filters per-user — each player's response only includes card faces they are allowed to see.

### 6.4 Peek & Flip Visibility Rules
- **Zone `flip_visibility = 'private'`**: When a player flips or peeks a card in this zone, only they see the face. All other players see a **grey placeholder with a "?" icon** plus an indicator showing who is looking.
- **Zone `flip_visibility = 'public'`**: When a player flips a card, all players see the face.
- **Cards in hand**: Always private. Other players see only the card backs. The count and arrangement of backs is visible (to show how many cards the player holds).
- **Peek action**: Adds the user's ID to the `peeked_by` JSON array. Other players are notified someone is peeking (they see the "?" placeholder). Unpeeking removes the ID.

---

## 7. Frontend Architecture

### 7.1 SPA Shell

`index.php` serves a minimal HTML shell:
```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Card Table</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/table.css">
    <link rel="stylesheet" href="/assets/css/zone-builder.css">
</head>
<body>
    <div id="app"></div>
    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
```

### 7.2 Client-Side Router
A hash-based router (`#/login`, `#/lobby`, `#/table/{id}`, `#/templates`, `#/admin`, `#/history/{id}`). Each route maps to a view module that exports `mount(container)` and `unmount()` methods.

### 7.3 State Management
A simple pub/sub store (`Store` class):
```javascript
// Singleton store
const store = {
    user: null,
    currentTable: null,
    tables: [],       // Lobby list
    // ... 
    listeners: new Map(),
    set(key, value) { this[key] = value; this.emit(key); },
    on(key, fn) { ... },
    off(key, fn) { ... },
    emit(key) { ... }
};
```

### 7.4 API Client
A `fetch` wrapper that:
- Includes credentials (`credentials: 'same-origin'`).
- Sets `Content-Type: application/json`.
- On 401 → redirects to `#/login`.
- Returns parsed JSON.

### 7.5 Pusher Client Manager
```javascript
class PusherManager {
    constructor(appKey, cluster) { ... }
    
    subscribeToLobby() { /* presence-lobby channel */ }
    unsubscribeFromLobby() { ... }
    
    subscribeToTable(tableId) {
        // private-table-{tableId}
        // Binds all game events → dispatches to store
    }
    unsubscribeFromTable(tableId) { ... }
    
    subscribeToUser(userId) {
        // private-user-{userId}
        // Personal notifications
    }
}
```

---

## 8. Zone Builder (Drag-to-Draw Canvas)

### 8.1 Overview
A full-screen canvas UI used during table creation (before `game.started`). The creator draws rectangular zones on a top-down view of a card table.

### 8.2 Interactions
- **Draw zone**: Click/touch and drag to draw a rectangle. On release, a dialog appears to set label, zone type, layout mode, flip visibility, and color.
- **Move zone**: Drag an existing zone by its title bar.
- **Resize zone**: Drag handles on corners/edges.
- **Delete zone**: Click zone → press Delete key or tap trash icon.
- **Edit zone**: Double-click zone to reopen the settings dialog.

### 8.3 Implementation
- Use a `<div>` based overlay system (not `<canvas>`) for accessibility and easier interaction.
- Zones are absolutely-positioned `<div>` elements within a relatively-positioned container.
- All positions and dimensions stored as **percentages** (0–100) for responsive scaling.
- The container maintains a fixed aspect ratio (e.g., 16:10) to represent the table.
- Zones are rendered with their background color at 30% opacity, a 2px dashed border, and a centered label.
- The builder provides a toolbar with:
  - "Add Shared Zone" and "Add Per-Player Zone" buttons (alternative to freeform drawing).
  - Template load/save buttons.
  - A preview toggle to show how the layout looks with N players.
  - An "Apply Template" dropdown showing saved templates.

### 8.4 Per-Player Zone Handling
When a zone is marked as `per_player`:
- The builder shows it as a single prototype zone with a "per-player" badge.
- At game start (`POST /tables/{id}/start`), the server clones this zone N times (once per seated player), positioning each clone relative to the player's seat around the table. The `owner_player_id` is set on each clone.
- The positioning algorithm distributes per-player zones around the table edges based on seat count.

### 8.5 Responsive Table Layout
The game table view is a responsive container. The layout adapts:
- **Desktop**: Landscape orientation. The current player's hand is at the bottom. Other players distributed around the top and sides.
- **Mobile**: Portrait orientation. The current player's hand is at the bottom (larger touch target). The table area is scrollable/zoomable. Other players shown at the top in a compact strip.

---

## 9. Card Rendering & Interaction UX

### 9.1 Card Component
Each card is a `<div>` with:
- A face side (suit + rank SVG artwork) and a back side (skin image).
- CSS `transform: rotateY(180deg)` for flip animation.
- Absolute positioning within its parent zone or hand.
- A subtle drop shadow and slight rotation for a natural "played card" look.
- Size: approximately 70×100px on desktop, 50×72px on mobile (scales with viewport).

### 9.2 Interaction Model

The interaction model uses a **gesture-first, context-menu-second** approach:

#### Direct Gestures (no menu required)
| Gesture | Context | Action |
|---|---|---|
| **Double-tap** | Card on table (face-down) | Flip face-up |
| **Double-tap** | Card on table (face-up) | Flip face-down |
| **Drag** | Card in hand → table zone | Place card in zone |
| **Drag** | Card in hand → another player's seat area | Give card to player |
| **Drag** | Card in hand → within hand | Reorder hand |
| **Drag** | Card in zone → another zone | Move card between zones |
| **Drag** | Card in zone → own hand area | Take card into hand |
| **Long-press** | Any card you can see | Peek (hold to view, release to unpeek) |
| **Swipe down** | Card in hand | Quick-discard to default discard zone |

#### Context Menu (single tap on a card)
A **single tap** on a card opens a small radial or floating context menu showing **only the actions valid for that card in its current state and location**. Maximum 4–5 items visible at once, with a "More..." option if needed.

**Example context menus by situation:**

Card in your hand, face-down:
```
[Peek] [Play to Table] [Discard] [More ▸]
  More: [Give to...] [Offer to...] [Return to Deck] [Mark]
```

Card face-down on table in a shared zone:
```
[Peek] [Take] [Flip] [More ▸]
  More: [Move to...] [Remove from Play]
```

Card face-up in your hand:
```
[Play to Table] [Discard] [Give to...] [More ▸]
  More: [Flip Down] [Mark] [Return to Deck] [Offer to...]
```

Deck zone (tapping the deck, not a specific card):
```
[Draw] [Shuffle] [Cut] [Deal ▸]
  Deal: [Deal to All] [Deal to...specific player]
```

#### Player-to-Player Actions
When dragging a card toward another player's seat area, the drop target highlights and shows:
- **Hand area**: "Give to [name]"
- **Player's zone**: "Place in [name]'s area"

If the card is dropped on a player avatar directly, a quick chooser appears:
```
[Give] [Offer] [Force]
```

### 9.3 Card Visibility States
Each card has a visual treatment based on its state:
| State | Visual |
|---|---|
| Face-down, not peeked | Card back skin visible |
| Face-down, you are peeking | Face semi-visible with "eye" icon overlay; back visible to others |
| Face-down, someone else peeking | Card back visible + grey "?" badge + peeking player's avatar |
| Face-up (public zone) | Face fully visible to all |
| Face-up (private zone, you) | Face visible to you; "?" placeholder to others |
| Marked by you | Subtle corner dot (only visible to you) |
| Removed from play | Greyed out, 50% opacity, cannot interact |

### 9.4 Hand UI
The player's hand is a dedicated zone at the bottom of the screen:
- Cards fan out in an arc (like holding cards in real life) on desktop.
- Cards are in a horizontally scrollable row on mobile.
- Drag to reorder within the hand.
- The hand area is taller than other zones to be a comfortable touch target.
- Other players see the **backs** of all cards in a player's hand, arranged in a smaller fan near that player's seat. The count is implicitly visible by the number of card backs shown.

### 9.5 Drag & Drop Engine
A custom drag engine (`lib/drag.js`) that handles both mouse and touch:
- `pointerdown` → start tracking.
- `pointermove` → move a ghost card element, highlight valid drop targets.
- `pointerup` → determine drop target, execute action via API, animate card to final position.
- **Snap zones**: When dragging near a valid zone, the card snaps to the zone boundary for visual feedback.
- **Invalid drops**: Card animates back to its origin.
- Touch events use `touch-action: none` on interactive elements to prevent scroll conflicts.

### 9.6 Animations
All card movements, flips, and deals are animated using CSS transitions (300ms ease-in-out) for smooth, natural feedback. When the server broadcasts an event, the client animates the card from its previous position to its new position.

---

## 10. Action Feed

### 10.1 Display
A scrollable panel on the right (desktop) or a collapsible drawer from the bottom (mobile). Shows a chronological log of all actions at the table.

### 10.2 Entry Format
Each entry shows:
```
[10:30:15] Alice shuffled the deck.
[10:30:22] Alice dealt 2 cards to Bob.
[10:30:25] Alice dealt 2 cards to Charlie.
[10:30:28] Bob peeked at a card.
[10:30:35] Charlie flipped a card face-up: 7♠.
[10:30:40] Bob placed a card face-down in the middle.
[10:31:02] Alice transferred 50 chips to Main Pot.
```

### 10.3 Implementation
- New entries arrive via Pusher events.
- On initial load or reconnect, the client fetches the last 50 entries from `GET /tables/{id}/actions`.
- Infinite scroll up loads older entries.
- Each entry type has a human-readable template. Card identities are only shown if the card is face-up/revealed.

---

## 11. Lobby

### 11.1 Layout
- **Left panel**: List of tables with `status=lobby`, showing table name, creator name, player count, deck count. Click to view details / join.
- **Right panel**: Online users list (from Pusher presence channel).
- **Top bar**: "Create Table" button, user avatar & name, logout.

### 11.2 Realtime Updates
- Pusher `presence-lobby` channel provides online user list.
- Lobby polls `GET /lobby/tables` every 10 seconds **or** listens to a `lobby-updates` event on the presence channel triggered when tables are created/joined/started/closed.

### 11.3 Table Creation Flow
1. Click "Create Table" → modal with:
   - Table name
   - Number of decks (1–8)
   - Include jokers toggle
   - Deck backs: Uniform / Random per deck
   - Deck skin picker (shows admin-uploaded skins)
   - Table logo picker
   - Initial chips per player (0 = disabled)
   - "Load Template" dropdown
2. Click "Next" → Zone Builder (full-screen).
3. In Zone Builder, draw zones, configure each one.
4. Click "Save & Open Table" → table enters `lobby` state, creator is first player.
5. Optionally "Save as Template" with a name and public/private toggle.

---

## 12. Chips System

### 12.1 Model
Chips are simple integer counts. Each player has a `chips` balance on `table_players`. Communal or named pots are rows in `chip_pots`.

### 12.2 UI
- Each player's seat shows their chip count as a styled badge.
- Pots are shown as labeled chip stacks in their associated zones (or floating near the center).
- **Transfer interaction**: Tap your chip count → a dialog appears with:
  - Amount input (slider + numeric).
  - Destination picker: a pot, or another player.
  - "Transfer" button.
- Alternatively, the creator can drag chips from their tray to a pot or player (stretch goal).

### 12.3 Validation
The server enforces:
- Cannot transfer more chips than the source has.
- Any player can transfer their own chips to any pot or player.
- The table creator can transfer from any pot to any player (acting as house).
- All transfers are logged in `chip_transactions` and broadcast via Pusher.

---

## 13. Chat System

### 13.1 Phrase Selection
Tapping the chat icon opens a scrollable grid/list of available phrases. Tapping a phrase sends it immediately.

### 13.2 Phrase Sources
1. **Default phrases** (`chat_phrases` where `is_default = 1`): Available on all tables.
2. **Table-specific phrases** (`chat_phrases` where `table_id = {current}`): Added by the table creator.
3. The creator can add/remove table-specific phrases during the lobby phase.

### 13.3 Display
Chat messages appear as speech bubbles near the sender's seat, visible for 5 seconds, then fade. They also appear in the action feed for permanent record.

### 13.4 Default Phrases (to be seeded)
```
"I've got a good feeling about this."
"This might work."
"Okay, I like this."
"That helps."
"We're in business."
"This could be big."
"Not bad… not bad."
"I'll take it."
"That's what I needed."
"Things are looking up."
"This seems safe."
"What could go wrong?"
"I'm just going to try this."
"Let's see how this goes…"
"Aww DANG IT."
"Well, that didn't work."
"I did not see that coming."
"Okay, okay, I can work with this."
"Anyone want to trade?"
"Your turn!"
"Nice move."
"Whoa."
"Good game!"
"One more round?"
"I'm thinking…"
"Hold on, let me think."
"Okay, I'm ready."
"Deal me in."
"I fold."
"I'm all in!"
"Check."
"Call."
"Raise."
```

---

## 14. Admin Panel

### 14.1 Access
Route: `#/admin`. Requires session with `is_admin = true`. Same OTP login flow — the system checks the email against the admin list.

### 14.2 Sections

**Dashboard**: Active tables count, online users count, total users, total games played.

**Deck Skins**:
- Grid of uploaded card back images.
- Upload form: name + image file (PNG/JPG, recommended 250×350px).
- Toggle active/inactive.
- Delete (cannot delete if in use by an active table).

**Table Logos**:
- Same pattern as deck skins.
- Image dimensions: recommended 400×400px, displayed at reduced size.

**Chat Phrases**:
- CRUD list of default phrases.
- Bulk import/export as plain text (one phrase per line).

**Users**: Read-only list with email, display name, created date, last active.

**Tables**: List all tables (filterable by status) with player count and created date. View action log for any table.

---

## 15. Game History & Review

### 15.1 Access
Route: `#/history/{tableId}`. Available for tables with `status=closed`.

### 15.2 Features
- **Action log replay**: Scrollable, filterable action feed for the entire game.
- **Filter by player**: Show only actions by a specific player.
- **Filter by action type**: e.g., show only card flips, or only chip transfers.
- **Stats summary**: Cards dealt, total chip movements, game duration, number of shuffles, etc.
- **Export**: Download action log as JSON.

### 15.3 Future Consideration
A step-through replay mode (like chess.com's game review) where you can step forward/backward through actions and see the table state at each point. This requires the client to reconstruct state from the action log. This is a stretch goal but the action log schema is designed to support it.

---

## 16. Security

### 16.1 API Security
- All endpoints require authentication (except `/auth/request-otp` and `/auth/verify-otp`).
- Admin endpoints additionally require `is_admin` session flag.
- Table actions validate that the acting user is a member of the table.
- Creator-only actions validate `tables.creator_id` matches the session user.
- Card actions validate the actor has access to the card (it's in their hand, or in a zone they can interact with).
- Rate limiting on OTP requests: max 5 per email per 15 minutes (tracked in DB).

### 16.2 Card Privacy
- **The server never sends card suit/rank data to a player who should not see it.** This is the most critical security rule.
- The `GET /tables/{id}/state` endpoint and all Pusher events filter card data per-recipient.
- For Pusher: since Pusher broadcasts to an entire channel, the server uses **Pusher's "client events" sparingly**. For card-sensitive events, the server sends different payloads to different users via multiple Pusher triggers (one per unique "view" of the event), or uses private user channels for sensitive data.
- Alternative approach for card privacy with Pusher: The broadcast event includes only card IDs and metadata (zone, face_up). If a player needs to see a card face, they fetch it individually via `GET /tables/{id}/cards/{cardId}` which returns face data only if the player has visibility rights.

### 16.3 Input Validation
- All inputs validated and sanitized server-side.
- Prepared statements for all SQL (PDO).
- File uploads (admin skins/logos) validated for type (image/*), size (<2MB), and stored outside the document root with served via a PHP passthrough or in `public/uploads/` with no PHP execution.

### 16.4 CORS & CSRF
- API only serves same-origin requests (no CORS headers set = browser blocks cross-origin).
- Session cookie is `SameSite=Strict`.
- For additional CSRF protection, a `X-Requested-With: XMLHttpRequest` header is required on all API requests and validated server-side.

---

## 17. Performance & Optimization

### 17.1 Database
- Indexes on all foreign keys and frequently queried columns (see schema).
- `action_log` will grow large. Partition by `table_id` or archive closed tables to a separate table/database after 30 days.
- Connection pooling: PHP-FPM persistent connections to MySQL.

### 17.2 Pusher
- Pusher free tier: 200k messages/day, 100 connections. Paid tiers scale from there.
- Batch event triggers where possible (Pusher supports triggering multiple events in one HTTP call).
- Client reconnection: Pusher JS SDK handles reconnection automatically. On reconnect, the client fetches full state from `GET /tables/{id}/state`.

### 17.3 Frontend
- No framework overhead — vanilla JS keeps the bundle small.
- Card face SVGs are lightweight and cached.
- Lazy load card skins (branded backs) on first use.
- Minimize DOM nodes: only render cards visible on screen (for large hands, virtualize the scroll).

### 17.4 Memory (1GB Droplet)
- MySQL: ~300MB allocated.
- Apache/PHP-FPM: ~500MB for worker processes.
- OS: ~200MB.
- PHP-FPM config: `pm = dynamic`, `pm.max_children = 10`, `pm.start_servers = 3`.

---

## 18. Deployment

### 18.1 Server Setup
1. DigitalOcean Droplet: Ubuntu 22.04 LTS, 1GB RAM.
2. Install: Apache, PHP 8.2, MySQL 8.0, Certbot.
3. Configure Apache VirtualHost with SSL.
4. Clone repo to `/var/www/cardtable/`.
5. `composer install` for Pusher SDK.
6. Run `php scripts/migrate.php` to create schema.
7. Run `php scripts/seed_phrases.php` to insert default chat phrases.
8. Configure `src/config.php` with DB credentials, Pusher keys, SMTP settings.
9. Configure `src/admins.php` with admin email addresses.
10. Set up cron: `*/5 * * * * php /var/www/cardtable/scripts/cleanup_sessions.php` for session garbage collection.

### 18.2 Apache Config
```apache
<VirtualHost *:443>
    ServerName cardtable.example.com
    DocumentRoot /var/www/cardtable/public
    
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/cardtable.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/cardtable.example.com/privkey.pem
    
    <Directory /var/www/cardtable/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    # Prevent access to src/ directory
    <Directory /var/www/cardtable/src>
        Require all denied
    </Directory>
</VirtualHost>
```

### 18.3 .htaccess (public/)
```apache
RewriteEngine On

# API routes → api/router.php
RewriteRule ^api/(.*)$ api/router.php [L,QSA]

# Static assets → serve directly
RewriteCond %{REQUEST_URI} ^/assets/ [OR]
RewriteCond %{REQUEST_URI} ^/uploads/
RewriteRule ^ - [L]

# Everything else → SPA shell
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

---

## 19. API Router Implementation

### 19.1 Pattern
`api/router.php` parses `$_SERVER['REQUEST_URI']`, extracts the HTTP method, and dispatches to the appropriate controller method.

```php
// Simplified router pattern
$method = $_SERVER['REQUEST_METHOD'];
$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$uri = preg_replace('#^api/v1/#', '', $uri);
$segments = explode('/', $uri);

// Match routes
// "auth/request-otp" → AuthController::requestOtp()
// "tables/{id}/cards/action" → CardController::handleAction($id)
// etc.
```

### 19.2 Controller Pattern
Each controller is a plain PHP class with static methods. Methods receive parsed request data and return arrays that the router encodes as JSON.

```php
class CardController {
    public static function handleAction(string $tableId): array {
        $user = requireAuth();
        $body = jsonBody();
        $action = $body['action'] ?? '';
        
        // Validate table membership
        // Validate action is valid for current card state
        // Execute action (DB updates)
        // Log to action_log
        // Trigger Pusher events
        // Return updated state
    }
}
```

---

## 20. Offer System

### 20.1 Flow
1. Player A sends `offer` action → creates a pending offer record (use `action_log` with `action_type = 'card.offered'` and a status flag, or a dedicated `offers` table).
2. Server broadcasts `card.offered` event → Player B's UI shows an offer prompt.
3. Player B accepts or declines → `accept_offer` or `decline_offer` action.
4. On accept: cards are transferred. On decline: offer is cancelled. Both are logged and broadcast.

### 20.2 Schema Addition
```sql
CREATE TABLE offers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    from_player_id  INT UNSIGNED NOT NULL,
    to_player_id    INT UNSIGNED NOT NULL,
    card_ids        JSON NOT NULL,
    status          ENUM('pending','accepted','declined','expired') DEFAULT 'pending',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at     DATETIME NULL,
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    INDEX idx_table_status (table_id, status)
) ENGINE=InnoDB;
```

---

## 21. Implementation Order (for Claude Code)

Build in this sequence, testing each phase before moving on:

### Phase 1: Foundation
1. Directory structure and Apache config.
2. `config.php`, `db.php` (PDO connection), `helpers.php`.
3. Database migrations — run all `CREATE TABLE` statements.
4. `DbSessionHandler` class and session bootstrap.
5. Auth system: OTP request, verification, session creation, `/auth/me`.
6. Basic SPA shell: `index.php`, `app.js` with hash router, `api.js` client.
7. Login view (`views/login.js`).

### Phase 2: Lobby & Table Setup
8. `LobbyController` and `lobby.js` view.
9. Pusher integration: server-side SDK, Pusher auth endpoint, `PusherManager` on client.
10. `presence-lobby` channel for online users.
11. `TableController` — create, join, leave, kick.
12. `table-create.js` view with configuration form.
13. Zone Builder (`zone-builder.js`) — drag-to-draw UI for zone creation.
14. `ZoneController` CRUD endpoints.
15. Template system (`TemplateController`, `templates.js` view).

### Phase 3: Core Gameplay
16. Card generation on `game.start` (populate `cards` table for N decks).
17. Per-player zone cloning logic.
18. `CardController` — implement all action types, one by one.
19. Card rendering component (`components/card.js`).
20. Hand component (`components/hand.js`) with drag-to-reorder.
21. Zone rendering (`components/zone.js`) with card stacking/spreading.
22. Drag & drop engine (`lib/drag.js`) — mouse + touch unified.
23. Gesture detection (`lib/gestures.js`) — double-tap, long-press.
24. Context menu component (`components/context-menu.js`).
25. Pusher event handling for all card events.
26. Card visibility/privacy filtering on server and client.
27. Action feed component (`components/action-feed.js`).
28. `ActionLogController` for paginated log retrieval.

### Phase 4: Chips & Chat
29. `ChipController` — transfers, pot creation.
30. Chip tray component (`components/chip-tray.js`).
31. Chat system — phrases, sending, display.
32. `ChatController` and chat component.

### Phase 5: Game Lifecycle
33. Pause, resume, close table.
34. Disconnect/reconnect handling (re-fetch full state on Pusher reconnect).
35. Save/resume: full state restoration on table reopen.
36. Game history view (`views/history.js`).

### Phase 6: Admin
37. Admin panel (`views/admin.js`).
38. Deck skin upload and management.
39. Table logo upload and management.
40. Chat phrase management.
41. Admin dashboard stats.

### Phase 7: Polish
42. Animations (card flip, deal, move).
43. Sound effects (shuffle, deal, flip — optional).
44. Mobile-specific optimizations.
45. Performance testing with 10 concurrent tables.
46. Security audit (card privacy, input validation, session handling).

---

## 22. Default Chat Phrases Seed Data

```json
[
  "I've got a good feeling about this.",
  "This might work.",
  "Okay, I like this.",
  "That helps.",
  "We're in business.",
  "This could be big.",
  "Not bad… not bad.",
  "I'll take it.",
  "That's what I needed.",
  "Things are looking up.",
  "This seems safe.",
  "What could go wrong?",
  "I'm just going to try this.",
  "Let's see how this goes…",
  "Aww DANG IT.",
  "Well, that didn't work.",
  "I did not see that coming.",
  "Okay, okay, I can work with this.",
  "Anyone want to trade?",
  "Your turn!",
  "Nice move.",
  "Whoa.",
  "Good game!",
  "One more round?",
  "I'm thinking…",
  "Hold on, let me think.",
  "Okay, I'm ready.",
  "Deal me in.",
  "I fold.",
  "I'm all in!",
  "Check.",
  "Call.",
  "Raise."
]
```

---

## 23. Environment Configuration Template

```php
// src/config.php
return [
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'cardtable',
        'username' => 'cardtable_user',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],
    'pusher' => [
        'app_id'  => 'YOUR_APP_ID',
        'key'     => 'YOUR_KEY',
        'secret'  => 'YOUR_SECRET',
        'cluster' => 'YOUR_CLUSTER',
        'use_tls' => true,
    ],
    'mail' => [
        'smtp_host' => 'smtp.mailgun.org',
        'smtp_port' => 587,
        'smtp_user' => 'CHANGE_ME',
        'smtp_pass' => 'CHANGE_ME',
        'from_addr' => 'noreply@cardtable.example.com',
        'from_name' => 'Card Table',
    ],
    'app' => [
        'name'           => 'Card Table',
        'url'            => 'https://cardtable.example.com',
        'session_ttl'    => 86400, // 24 hours
        'otp_ttl'        => 600,   // 10 minutes
        'otp_max_attempts' => 5,   // Per email per 15 min
        'max_upload_size'  => 2097152, // 2MB
    ],
];
```
