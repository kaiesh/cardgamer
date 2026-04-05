-- Full schema for Card Table Platform

CREATE TABLE IF NOT EXISTS users (
    id              CHAR(36) PRIMARY KEY,
    email           VARCHAR(255) NOT NULL UNIQUE,
    display_name    VARCHAR(50) NULL,
    avatar_color    VARCHAR(7) DEFAULT '#3B82F6',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS otp_codes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255) NOT NULL,
    otp_hash        VARCHAR(255) NOT NULL,
    expires_at      DATETIME NOT NULL,
    used            TINYINT(1) DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_expires (email, expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sessions (
    id              VARCHAR(128) PRIMARY KEY,
    user_id         CHAR(36) NULL,
    data            MEDIUMTEXT NOT NULL,
    last_activity   INT UNSIGNED NOT NULL,
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tables` (
    id              CHAR(36) PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    creator_id      CHAR(36) NOT NULL,
    status          ENUM('lobby','active','paused','closed') DEFAULT 'lobby',
    num_decks       TINYINT UNSIGNED DEFAULT 1,
    include_jokers  TINYINT(1) DEFAULT 0,
    deck_backs      ENUM('uniform','random_per_deck') DEFAULT 'uniform',
    deck_skin_id    INT UNSIGNED NULL,
    table_logo_id   INT UNSIGNED NULL,
    chip_initial    INT UNSIGNED DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    paused_at       DATETIME NULL,
    FOREIGN KEY (creator_id) REFERENCES users(id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS table_players (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    user_id         CHAR(36) NOT NULL,
    seat_index      TINYINT UNSIGNED NOT NULL,
    is_connected    TINYINT(1) DEFAULT 1,
    chips           INT UNSIGNED DEFAULT 0,
    joined_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_table_user (table_id, user_id),
    UNIQUE KEY uk_table_seat (table_id, seat_index),
    FOREIGN KEY (table_id) REFERENCES `tables`(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS zones (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    label           VARCHAR(50) NOT NULL,
    zone_type       ENUM('shared','per_player') NOT NULL,
    owner_player_id INT UNSIGNED NULL,
    layout_mode     ENUM('stacked','spread') DEFAULT 'stacked',
    flip_visibility ENUM('private','public') DEFAULT 'private',
    pos_x           FLOAT NOT NULL,
    pos_y           FLOAT NOT NULL,
    width           FLOAT NOT NULL,
    height          FLOAT NOT NULL,
    color           VARCHAR(7) DEFAULT '#1E3A5F',
    z_order         SMALLINT UNSIGNED DEFAULT 0,
    FOREIGN KEY (table_id) REFERENCES `tables`(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_player_id) REFERENCES table_players(id) ON DELETE SET NULL,
    INDEX idx_table (table_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cards (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    deck_index      TINYINT UNSIGNED NOT NULL,
    suit            ENUM('hearts','diamonds','clubs','spades','joker') NOT NULL,
    `rank`          VARCHAR(5) NOT NULL,
    face_up         TINYINT(1) DEFAULT 0,
    zone_id         INT UNSIGNED NULL,
    holder_player_id INT UNSIGNED NULL,
    position_in_zone INT UNSIGNED DEFAULT 0,
    peeked_by       JSON DEFAULT NULL,
    marked_by       JSON DEFAULT NULL,
    in_play         TINYINT(1) DEFAULT 1,
    back_skin_id    INT UNSIGNED NULL,
    FOREIGN KEY (table_id) REFERENCES `tables`(id) ON DELETE CASCADE,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
    FOREIGN KEY (holder_player_id) REFERENCES table_players(id) ON DELETE SET NULL,
    INDEX idx_table_zone (table_id, zone_id),
    INDEX idx_table_holder (table_id, holder_player_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chip_pots (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    label           VARCHAR(50) NOT NULL,
    zone_id         INT UNSIGNED NULL,
    owner_player_id INT UNSIGNED NULL,
    amount          INT UNSIGNED DEFAULT 0,
    FOREIGN KEY (table_id) REFERENCES `tables`(id) ON DELETE CASCADE,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
    FOREIGN KEY (owner_player_id) REFERENCES table_players(id) ON DELETE SET NULL,
    INDEX idx_table (table_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chip_transactions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    from_type       ENUM('player','pot') NOT NULL,
    from_id         INT UNSIGNED NOT NULL,
    to_type         ENUM('player','pot') NOT NULL,
    to_id           INT UNSIGNED NOT NULL,
    amount          INT UNSIGNED NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES `tables`(id) ON DELETE CASCADE,
    INDEX idx_table_time (table_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS action_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    user_id         CHAR(36) NOT NULL,
    action_type     VARCHAR(50) NOT NULL,
    payload         JSON NOT NULL,
    created_at      DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
    FOREIGN KEY (table_id) REFERENCES `tables`(id) ON DELETE CASCADE,
    INDEX idx_table_time (table_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_phrases (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phrase          VARCHAR(200) NOT NULL,
    is_default      TINYINT(1) DEFAULT 1,
    table_id        CHAR(36) NULL,
    FOREIGN KEY (table_id) REFERENCES `tables`(id) ON DELETE CASCADE,
    INDEX idx_table (table_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS table_chat (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    user_id         CHAR(36) NOT NULL,
    phrase_id       INT UNSIGNED NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES `tables`(id) ON DELETE CASCADE,
    FOREIGN KEY (phrase_id) REFERENCES chat_phrases(id),
    INDEX idx_table_time (table_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS templates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    creator_id      CHAR(36) NOT NULL,
    name            VARCHAR(100) NOT NULL,
    is_public       TINYINT(1) DEFAULT 0,
    num_decks       TINYINT UNSIGNED DEFAULT 1,
    include_jokers  TINYINT(1) DEFAULT 0,
    deck_backs      ENUM('uniform','random_per_deck') DEFAULT 'uniform',
    chip_initial    INT UNSIGNED DEFAULT 0,
    custom_phrases  JSON DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creator_id) REFERENCES users(id),
    INDEX idx_public (is_public),
    INDEX idx_creator (creator_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS template_zones (
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

CREATE TABLE IF NOT EXISTS deck_skins (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    back_image_path VARCHAR(255) NOT NULL,
    is_active       TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS table_logos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    image_path      VARCHAR(255) NOT NULL,
    is_active       TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS offers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id        CHAR(36) NOT NULL,
    from_player_id  INT UNSIGNED NOT NULL,
    to_player_id    INT UNSIGNED NOT NULL,
    card_ids        JSON NOT NULL,
    status          ENUM('pending','accepted','declined','expired') DEFAULT 'pending',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at     DATETIME NULL,
    FOREIGN KEY (table_id) REFERENCES `tables`(id) ON DELETE CASCADE,
    INDEX idx_table_status (table_id, status)
) ENGINE=InnoDB;
