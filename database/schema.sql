-- =============================================================================
--  Taylor's Events : database schema
--  Engine   : MySQL 8.0.16 or newer, InnoDB. Not MariaDB: the design relies on
--             enforced CHECK constraints, JSON columns and row level locking.
--  Charset  : utf8mb4 / utf8mb4_unicode_ci
--  Timezone : every DATETIME is stored in Asia/Kuala_Lumpur (+08:00). The PHP
--             layer runs SET time_zone on each connection.
--
--  Session variables read by the triggers (set by App\Core\Database):
--    @app_user_id  id of the signed-in actor, NULL for guests and system jobs
--    @app_ip       client address, copied into audit_logs
--    @seed_mode    1 only while seed.sql or a test fixture is loading. It is
--                  ignored for the application account, so the web app can
--                  never switch the rules off.
--
--  Every business rule is enforced twice: once in a PHP service, and once
--  here, so a bug in a controller cannot corrupt the data. Trigger errors
--  start with a rule name (VENUE_CLASH:, LIFECYCLE:, ...) that the PHP layer
--  maps to a message in app/Views/lang/en.php.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';

DROP DATABASE IF EXISTS taylors_events;
CREATE DATABASE taylors_events CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE taylors_events;

-- -----------------------------------------------------------------------------
-- faculties : the schools of the university
-- -----------------------------------------------------------------------------
CREATE TABLE faculties (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(12)  NOT NULL,
    name        VARCHAR(120) NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_faculties_code (code)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- users : one table, three roles. Soft deleted through deleted_at.
-- -----------------------------------------------------------------------------
CREATE TABLE users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id          SMALLINT UNSIGNED NULL,
    student_number      VARCHAR(20)  NULL,
    full_name           VARCHAR(120) NOT NULL,
    email               VARCHAR(190) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,              -- Argon2id
    role                ENUM('student','organizer','admin') NOT NULL DEFAULT 'student',
    bio                 VARCHAR(500) NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    failed_login_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until        DATETIME     NULL,
    last_login_at       DATETIME     NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_student_number (student_number),
    KEY idx_users_role (role, full_name),
    KEY idx_users_faculty (faculty_id),
    CONSTRAINT fk_users_faculty FOREIGN KEY (faculty_id)
        REFERENCES faculties (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_users_email CHECK (email LIKE '%_@_%._%')
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- user_sessions : PHP sessions live here, not in files, so a session can be
-- revoked and survives a server restart.
-- -----------------------------------------------------------------------------
CREATE TABLE user_sessions (
    id               VARCHAR(128)  NOT NULL,
    user_id          INT UNSIGNED  NULL,
    ip_address       VARCHAR(45)   NOT NULL,
    user_agent_hash  CHAR(64)      NOT NULL,
    payload          MEDIUMBLOB    NOT NULL,
    last_activity    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_activity (last_activity),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- rate_limits : fixed window counters, keyed by "action:identifier"
-- -----------------------------------------------------------------------------
CREATE TABLE rate_limits (
    bucket_key    VARCHAR(190) NOT NULL,
    hits          INT UNSIGNED NOT NULL DEFAULT 0,
    window_start  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until DATETIME     NULL,
    PRIMARY KEY (bucket_key),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- venues : rooms on Lakeside Campus
-- -----------------------------------------------------------------------------
CREATE TABLE venues (
    id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code           VARCHAR(20)  NOT NULL,
    name           VARCHAR(120) NOT NULL,
    building       VARCHAR(80)  NOT NULL,
    floor_label    VARCHAR(10)  NULL,
    capacity       SMALLINT UNSIGNED NOT NULL,
    venue_type     ENUM('hall','lecture_theatre','theatre','classroom','lab','studio','lounge','breakout') NOT NULL,
    has_projector  TINYINT(1) NOT NULL DEFAULT 0,
    is_accessible  TINYINT(1) NOT NULL DEFAULT 1,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at     DATETIME   NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_venues_code (code),
    CONSTRAINT chk_venues_capacity CHECK (capacity > 0)
) ENGINE=InnoDB;

CREATE TABLE event_categories (
    id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(60) NOT NULL,
    slug       VARCHAR(60) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- clubs : events belong to a club or office, not to one person, so hosting
-- survives a change of committee.
-- -----------------------------------------------------------------------------
CREATE TABLE clubs (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120) NOT NULL,
    slug          VARCHAR(120) NOT NULL,
    kind          ENUM('club','society','sports','office') NOT NULL DEFAULT 'club',
    contact_email VARCHAR(190) NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at    DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clubs_name (name),
    UNIQUE KEY uq_clubs_slug (slug)
) ENGINE=InnoDB;

-- club_members : who may act for which club. Only advisor, president and
-- committee may create events for it (checked in PHP and in trg_events_bi).
CREATE TABLE club_members (
    club_id     SMALLINT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    role        ENUM('advisor','president','committee','member') NOT NULL DEFAULT 'member',
    joined_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (club_id, user_id),
    KEY idx_club_members_user (user_id),
    CONSTRAINT fk_club_members_club FOREIGN KEY (club_id) REFERENCES clubs (id) ON DELETE CASCADE,
    CONSTRAINT fk_club_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- password_resets : only the SHA-256 of the token is stored, so a leaked
-- database cannot be used to take over accounts. Single use, 30 minutes.
-- -----------------------------------------------------------------------------
CREATE TABLE password_resets (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    token_hash    CHAR(64)     NOT NULL,
    expires_at    DATETIME     NOT NULL,
    used_at       DATETIME     NULL,
    requested_ip  VARCHAR(45)  NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id, created_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_password_resets_expiry CHECK (expires_at > created_at)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- events : driven by a state machine
--     draft -> pending_approval -> published -> ongoing -> completed -> archived
--     pending_approval -> draft   rejected by an administrator, reason required
--     published        -> draft   withdrawn, only while nobody holds a seat
--
-- confirmed_count is denormalised on purpose: it lets the capacity rule be a
-- CHECK constraint. It is maintained by the triggers on registrations, never
-- by application code.
-- -----------------------------------------------------------------------------
CREATE TABLE events (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    organizer_id      INT UNSIGNED NOT NULL,
    club_id           SMALLINT UNSIGNED NOT NULL,
    category_id       SMALLINT UNSIGNED NOT NULL,
    venue_id          SMALLINT UNSIGNED NOT NULL,
    title             VARCHAR(150) NOT NULL,
    slug              VARCHAR(170) NOT NULL,
    summary           VARCHAR(300) NOT NULL,
    description       TEXT         NOT NULL,
    starts_at         DATETIME     NOT NULL,
    ends_at           DATETIME     NOT NULL,
    capacity          SMALLINT UNSIGNED NOT NULL,
    confirmed_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status            ENUM('draft','pending_approval','published','ongoing','completed','archived')
                      NOT NULL DEFAULT 'draft',
    rejection_reason  VARCHAR(500) NULL,
    approved_by       INT UNSIGNED NULL,
    approved_at       DATETIME     NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_events_slug (slug),
    -- Venue clash test: equality on venue, IN on status, range on time.
    -- Measured in docs/PERFORMANCE.md.
    KEY idx_events_venue_time (venue_id, status, starts_at, ends_at),
    -- Browse page: published and upcoming, ordered by start.
    KEY idx_events_status_start (status, starts_at),
    KEY idx_events_organizer (organizer_id),
    KEY idx_events_club (club_id, starts_at),
    KEY idx_events_category (category_id, status, starts_at),
    CONSTRAINT fk_events_organizer FOREIGN KEY (organizer_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_events_club      FOREIGN KEY (club_id)      REFERENCES clubs (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_events_category  FOREIGN KEY (category_id)  REFERENCES event_categories (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_events_venue     FOREIGN KEY (venue_id)     REFERENCES venues (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_events_approver  FOREIGN KEY (approved_by)  REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_events_time        CHECK (ends_at > starts_at),
    CONSTRAINT chk_events_capacity    CHECK (capacity > 0),
    -- GUARANTEE 1: a seat cannot be sold twice.
    CONSTRAINT chk_events_no_overbook CHECK (confirmed_count <= capacity)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- registrations : one row per (event, user). Booking again after cancelling
-- reuses the row. Waitlist order is queued_at, then id.
--
-- ticket_nonce is a random value bound into the signed QR payload. The HMAC
-- signature itself is recomputed on every scan and never stored, so reading
-- this table is not enough to forge a ticket.
-- -----------------------------------------------------------------------------
CREATE TABLE registrations (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id           INT UNSIGNED NOT NULL,
    user_id            INT UNSIGNED NOT NULL,
    status             ENUM('confirmed','waitlisted','cancelled') NOT NULL,
    ticket_nonce       CHAR(32)     NOT NULL,
    queued_at          DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    promoted_at        DATETIME     NULL,
    cancelled_at       DATETIME     NULL,
    checked_in_at      DATETIME     NULL,
    checked_in_by      INT UNSIGNED NULL,
    certificate_code   CHAR(12)     NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registrations_event_user (event_id, user_id),
    UNIQUE KEY uq_registrations_nonce (ticket_nonce),
    UNIQUE KEY uq_registrations_certificate (certificate_code),
    KEY idx_registrations_queue (event_id, status, queued_at),
    KEY idx_registrations_user (user_id, status),
    CONSTRAINT fk_registrations_event   FOREIGN KEY (event_id)      REFERENCES events (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_registrations_user    FOREIGN KEY (user_id)       REFERENCES users (id)  ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_registrations_checker FOREIGN KEY (checked_in_by) REFERENCES users (id)  ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_registrations_checkin CHECK (checked_in_at IS NULL OR status = 'confirmed'),
    CONSTRAINT chk_registrations_cert    CHECK (certificate_code IS NULL OR checked_in_at IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    type        VARCHAR(40)  NOT NULL,
    title       VARCHAR(150) NOT NULL,
    body        VARCHAR(500) NOT NULL,
    link_url    VARCHAR(255) NULL,
    read_at     DATETIME     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user_unread (user_id, read_at, id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_ratings (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id    INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL,
    comment     VARCHAR(1000) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ratings_event_user (event_id, user_id),
    KEY idx_ratings_user (user_id),
    CONSTRAINT fk_ratings_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_ratings_user  FOREIGN KEY (user_id)  REFERENCES users (id)  ON DELETE CASCADE,
    CONSTRAINT chk_ratings_range CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- audit_logs : append only. No foreign key on actor_id on purpose, so the
-- record of what an account did survives the deletion of that account.
-- -----------------------------------------------------------------------------
CREATE TABLE audit_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id     INT UNSIGNED NULL,
    action       VARCHAR(40)  NOT NULL,
    entity_type  VARCHAR(40)  NOT NULL,
    entity_id    INT UNSIGNED NULL,
    before_data  JSON         NULL,
    after_data   JSON         NULL,
    ip_address   VARCHAR(45)  NULL,
    created_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity_type, entity_id, created_at),
    KEY idx_audit_actor (actor_id, created_at),
    KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB;

-- idempotency_keys : a repeated POST with the same key returns the stored
-- outcome instead of running twice (double click, refresh, retry).
CREATE TABLE idempotency_keys (
    idem_key       CHAR(36)     NOT NULL,
    user_id        INT UNSIGNED NOT NULL,
    endpoint       VARCHAR(120) NOT NULL,
    response_code  SMALLINT UNSIGNED NOT NULL,
    response_body  JSON         NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (idem_key),
    KEY idx_idem_created (created_at),
    CONSTRAINT fk_idem_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ticket_scans : every scan at the door, refused ones included. Append only.
CREATE TABLE ticket_scans (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NULL,
    scanned_by       INT UNSIGNED NULL,
    result           ENUM('admitted','duplicate','invalid_signature','wrong_event',
                          'not_confirmed','event_not_live') NOT NULL,
    payload_hash     CHAR(64)     NOT NULL,
    ip_address       VARCHAR(45)  NULL,
    created_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_scans_event_time (event_id, created_at),
    KEY idx_scans_registration (registration_id),
    CONSTRAINT fk_scans_event        FOREIGN KEY (event_id)        REFERENCES events (id) ON DELETE RESTRICT,
    CONSTRAINT fk_scans_registration FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_scans_user         FOREIGN KEY (scanned_by)      REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- mail_outbox : every message the system sends. With the "log" transport the
-- message stays here and is read from the admin outbox page, so the whole
-- product can be demonstrated with no network.
CREATE TABLE mail_outbox (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    to_email         VARCHAR(190) NOT NULL,
    related_user_id  INT UNSIGNED NULL,
    template         VARCHAR(60)  NOT NULL,
    subject          VARCHAR(200) NOT NULL,
    body_text        TEXT         NOT NULL,
    transport        ENUM('log','smtp') NOT NULL DEFAULT 'log',
    sent_at          DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mail_created (created_at),
    CONSTRAINT fk_mail_user FOREIGN KEY (related_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================================================
--  TRIGGERS
-- =============================================================================
DELIMITER $$

-- Seed mode is honoured for every account except the application account.
-- The expression is repeated inline because the application account has no
-- EXECUTE privilege, by design.

-- -----------------------------------------------------------------------------
-- events BEFORE INSERT
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_events_bi BEFORE INSERT ON events FOR EACH ROW
BEGIN
    DECLARE v_seed TINYINT DEFAULT (COALESCE(@seed_mode, 0) = 1 AND USER() NOT LIKE 'taylors_app@%');
    DECLARE v_venue_capacity SMALLINT UNSIGNED;
    DECLARE v_clashes INT DEFAULT 0;
    DECLARE v_member INT DEFAULT 0;

    IF NOT v_seed THEN
        IF NEW.status <> 'draft' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LIFECYCLE: a new event must start as a draft';
        END IF;
        SET NEW.confirmed_count = 0;

        SELECT COUNT(*) INTO v_member FROM club_members
         WHERE club_id = NEW.club_id AND user_id = NEW.organizer_id
           AND role IN ('advisor','president','committee');
        IF v_member = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CLUB_SCOPE: the organiser is not on the committee of this club';
        END IF;
    END IF;

    SELECT capacity INTO v_venue_capacity FROM venues WHERE id = NEW.venue_id;
    IF NEW.capacity > v_venue_capacity THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'VENUE_CAPACITY: the event is larger than the room';
    END IF;

    -- GUARANTEE 2. Two intervals overlap exactly when s1 < e2 AND e1 > s2.
    -- Only events that actually hold the room are compared. FOR SHARE makes
    -- this a locking read: it sees every committed booking, not an older
    -- snapshot, and it blocks a second transaction from slipping an
    -- overlapping event into the same range before this one commits.
    IF NEW.status IN ('pending_approval','published','ongoing') THEN
        SELECT COUNT(*) INTO v_clashes FROM events e
         WHERE e.venue_id = NEW.venue_id
           AND e.status IN ('pending_approval','published','ongoing')
           AND e.deleted_at IS NULL
           AND e.starts_at < NEW.ends_at
           AND e.ends_at   > NEW.starts_at
           FOR SHARE;
        IF v_clashes > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'VENUE_CLASH: the venue is already booked for an overlapping time';
        END IF;
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- events BEFORE UPDATE : state machine, approval rules, capacity, venue clash
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_events_bu BEFORE UPDATE ON events FOR EACH ROW
BEGIN
    DECLARE v_seed TINYINT DEFAULT (COALESCE(@seed_mode, 0) = 1 AND USER() NOT LIKE 'taylors_app@%');
    DECLARE v_venue_capacity SMALLINT UNSIGNED;
    DECLARE v_clashes INT DEFAULT 0;
    DECLARE v_approver_role VARCHAR(20);
    DECLARE v_approver_in_club INT DEFAULT 0;

    IF NEW.status <> OLD.status AND NOT v_seed THEN
        IF NOT (
               (OLD.status = 'draft'            AND NEW.status = 'pending_approval')
            OR (OLD.status = 'pending_approval' AND NEW.status IN ('published','draft'))
            OR (OLD.status = 'published'        AND NEW.status IN ('ongoing','draft'))
            OR (OLD.status = 'ongoing'          AND NEW.status = 'completed')
            OR (OLD.status = 'completed'        AND NEW.status = 'archived')
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LIFECYCLE: this status change is not allowed';
        END IF;

        IF OLD.status = 'pending_approval' AND NEW.status = 'draft'
           AND (NEW.rejection_reason IS NULL OR CHAR_LENGTH(TRIM(NEW.rejection_reason)) < 10) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REJECTION_REASON: a rejection needs a written reason';
        END IF;

        IF OLD.status = 'pending_approval' AND NEW.status = 'published' THEN
            -- An organiser must never approve their own event.
            IF NEW.approved_by IS NULL OR NEW.approved_by = NEW.organizer_id THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SELF_APPROVAL: an event cannot be approved by its own organiser';
            END IF;
            SELECT role INTO v_approver_role FROM users WHERE id = NEW.approved_by;
            SELECT COUNT(*) INTO v_approver_in_club FROM club_members
             WHERE club_id = NEW.club_id AND user_id = NEW.approved_by AND role <> 'member';
            IF v_approver_role <> 'admin' OR v_approver_in_club > 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SELF_APPROVAL: only an administrator outside the hosting club can approve';
            END IF;
            SET NEW.approved_at = NOW();
            SET NEW.rejection_reason = NULL;
        END IF;

        IF OLD.status = 'published' AND NEW.status = 'draft' AND OLD.confirmed_count > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LIFECYCLE: an event with booked seats cannot be withdrawn';
        END IF;
    END IF;

    IF NEW.capacity <> OLD.capacity OR NEW.venue_id <> OLD.venue_id THEN
        SELECT capacity INTO v_venue_capacity FROM venues WHERE id = NEW.venue_id;
        IF NEW.capacity > v_venue_capacity THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'VENUE_CAPACITY: the event is larger than the room';
        END IF;
    END IF;

    -- GUARANTEE 2 again, whenever the event starts holding the room or moves.
    IF NEW.status IN ('pending_approval','published','ongoing') AND NEW.deleted_at IS NULL
       AND (NEW.status <> OLD.status OR NEW.venue_id <> OLD.venue_id
            OR NEW.starts_at <> OLD.starts_at OR NEW.ends_at <> OLD.ends_at
            OR NOT (NEW.deleted_at <=> OLD.deleted_at)) THEN
        SELECT COUNT(*) INTO v_clashes FROM events e
         WHERE e.venue_id = NEW.venue_id
           AND e.id <> NEW.id
           AND e.status IN ('pending_approval','published','ongoing')
           AND e.deleted_at IS NULL
           AND e.starts_at < NEW.ends_at
           AND e.ends_at   > NEW.starts_at
           FOR SHARE;
        IF v_clashes > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'VENUE_CLASH: the venue is already booked for an overlapping time';
        END IF;
    END IF;
END$$

-- events AFTER INSERT / UPDATE : audit trail with before and after snapshots
CREATE TRIGGER trg_events_ai AFTER INSERT ON events FOR EACH ROW
BEGIN
    IF NOT (COALESCE(@seed_mode, 0) = 1 AND USER() NOT LIKE 'taylors_app@%') THEN
        INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address)
        VALUES (@app_user_id, 'event.create', 'event', NEW.id, NULL,
                JSON_OBJECT('title', NEW.title, 'status', NEW.status, 'venue_id', NEW.venue_id,
                            'starts_at', NEW.starts_at, 'ends_at', NEW.ends_at,
                            'capacity', NEW.capacity, 'club_id', NEW.club_id),
                @app_ip);
    END IF;
END$$

CREATE TRIGGER trg_events_au AFTER UPDATE ON events FOR EACH ROW
BEGIN
    -- Seat count changes are booking activity and are audited on registrations.
    IF NOT (COALESCE(@seed_mode, 0) = 1 AND USER() NOT LIKE 'taylors_app@%') AND (
           NOT (OLD.title <=> NEW.title)         OR NOT (OLD.status <=> NEW.status)
        OR NOT (OLD.venue_id <=> NEW.venue_id)   OR NOT (OLD.starts_at <=> NEW.starts_at)
        OR NOT (OLD.ends_at <=> NEW.ends_at)     OR NOT (OLD.capacity <=> NEW.capacity)
        OR NOT (OLD.deleted_at <=> NEW.deleted_at)) THEN
        INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address)
        VALUES (@app_user_id,
                CASE WHEN OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN 'event.delete'
                     WHEN OLD.status = 'pending_approval' AND NEW.status = 'draft' THEN 'event.rejected'
                     WHEN OLD.status <> NEW.status THEN CONCAT('event.', NEW.status)
                     ELSE 'event.update' END,
                'event', NEW.id,
                JSON_OBJECT('title', OLD.title, 'status', OLD.status, 'venue_id', OLD.venue_id,
                            'starts_at', OLD.starts_at, 'ends_at', OLD.ends_at, 'capacity', OLD.capacity),
                JSON_OBJECT('title', NEW.title, 'status', NEW.status, 'venue_id', NEW.venue_id,
                            'starts_at', NEW.starts_at, 'ends_at', NEW.ends_at, 'capacity', NEW.capacity,
                            'rejection_reason', NEW.rejection_reason, 'approved_by', NEW.approved_by),
                @app_ip);
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- registrations : keep events.confirmed_count true, whatever the caller does.
-- The UPDATE below fires chk_events_no_overbook, so a confirmed row that
-- would exceed capacity is refused by the database itself (GUARANTEE 1).
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_registrations_bi BEFORE INSERT ON registrations FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(20);
    IF NOT (COALESCE(@seed_mode, 0) = 1 AND USER() NOT LIKE 'taylors_app@%') THEN
        SELECT status INTO v_status FROM events WHERE id = NEW.event_id AND deleted_at IS NULL;
        IF v_status IS NULL OR v_status NOT IN ('published','ongoing') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EVENT_CLOSED: this event is not open for booking';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_registrations_ai AFTER INSERT ON registrations FOR EACH ROW
BEGIN
    IF NEW.status = 'confirmed' THEN
        UPDATE events SET confirmed_count = confirmed_count + 1 WHERE id = NEW.event_id;
    END IF;
    IF NOT (COALESCE(@seed_mode, 0) = 1 AND USER() NOT LIKE 'taylors_app@%') THEN
        INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address)
        VALUES (@app_user_id, CONCAT('registration.', NEW.status), 'registration', NEW.id, NULL,
                JSON_OBJECT('event_id', NEW.event_id, 'user_id', NEW.user_id, 'status', NEW.status), @app_ip);
    END IF;
END$$

CREATE TRIGGER trg_registrations_au AFTER UPDATE ON registrations FOR EACH ROW
BEGIN
    IF OLD.status <> 'confirmed' AND NEW.status = 'confirmed' THEN
        UPDATE events SET confirmed_count = confirmed_count + 1 WHERE id = NEW.event_id;
    ELSEIF OLD.status = 'confirmed' AND NEW.status <> 'confirmed' THEN
        UPDATE events SET confirmed_count = confirmed_count - 1 WHERE id = NEW.event_id;
    END IF;
    IF NOT (COALESCE(@seed_mode, 0) = 1 AND USER() NOT LIKE 'taylors_app@%')
       AND (NOT (OLD.status <=> NEW.status) OR NOT (OLD.checked_in_at <=> NEW.checked_in_at)) THEN
        INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address)
        VALUES (@app_user_id,
                CASE WHEN OLD.checked_in_at IS NULL AND NEW.checked_in_at IS NOT NULL THEN 'registration.checkin'
                     WHEN OLD.status = 'waitlisted' AND NEW.status = 'confirmed' THEN 'registration.promoted'
                     ELSE CONCAT('registration.', NEW.status) END,
                'registration', NEW.id,
                JSON_OBJECT('status', OLD.status, 'checked_in_at', OLD.checked_in_at),
                JSON_OBJECT('event_id', NEW.event_id, 'user_id', NEW.user_id,
                            'status', NEW.status, 'checked_in_at', NEW.checked_in_at),
                @app_ip);
    END IF;
END$$

-- Registrations are history: they are cancelled, never removed.
CREATE TRIGGER trg_registrations_bd BEFORE DELETE ON registrations FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REGISTRATION: registrations are cancelled, not deleted';
END$$

-- users : audit role changes and deactivation. Password hashes never enter the log.
CREATE TRIGGER trg_users_au AFTER UPDATE ON users FOR EACH ROW
BEGIN
    IF NOT (COALESCE(@seed_mode, 0) = 1 AND USER() NOT LIKE 'taylors_app@%') AND (
           NOT (OLD.role <=> NEW.role) OR NOT (OLD.is_active <=> NEW.is_active)
        OR NOT (OLD.deleted_at <=> NEW.deleted_at)) THEN
        INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address)
        VALUES (@app_user_id, 'user.update', 'user', NEW.id,
                JSON_OBJECT('role', OLD.role, 'is_active', OLD.is_active, 'deleted_at', OLD.deleted_at),
                JSON_OBJECT('role', NEW.role, 'is_active', NEW.is_active, 'deleted_at', NEW.deleted_at),
                @app_ip);
    END IF;
END$$

-- event_ratings : only someone who was checked in to a finished event may rate it
CREATE TRIGGER trg_ratings_bi BEFORE INSERT ON event_ratings FOR EACH ROW
BEGIN
    DECLARE v_attended INT DEFAULT 0;
    SELECT COUNT(*) INTO v_attended
      FROM registrations r JOIN events e ON e.id = r.event_id
     WHERE r.event_id = NEW.event_id AND r.user_id = NEW.user_id
       AND r.checked_in_at IS NOT NULL AND e.status IN ('completed','archived');
    IF v_attended = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RATING: only checked-in attendees of a finished event can rate it';
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- GUARANTEE 3: the audit log cannot be altered. The application account holds
-- only SELECT, INSERT, UPDATE, DELETE (see app_user.sql), so it cannot drop
-- these triggers either.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AUDIT_IMMUTABLE: the audit log is append only';
END$$

CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AUDIT_IMMUTABLE: the audit log is append only';
END$$

CREATE TRIGGER trg_scans_no_update BEFORE UPDATE ON ticket_scans FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SCANS_IMMUTABLE: the door log is append only';
END$$

CREATE TRIGGER trg_scans_no_delete BEFORE DELETE ON ticket_scans FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SCANS_IMMUTABLE: the door log is append only';
END$$

DELIMITER ;

-- =============================================================================
--  VIEWS : read models for the analytics dashboard
-- =============================================================================
CREATE VIEW v_event_stats AS
SELECT  e.id, e.slug, e.title, e.status, e.starts_at, e.club_id, e.category_id,
        e.capacity, e.confirmed_count,
        COALESCE(SUM(r.status = 'waitlisted'), 0)       AS waitlisted_count,
        COALESCE(SUM(r.checked_in_at IS NOT NULL), 0)   AS attended_count,
        ROUND(100 * e.confirmed_count / e.capacity, 1)  AS fill_rate_pct
FROM events e
LEFT JOIN registrations r ON r.event_id = e.id
WHERE e.deleted_at IS NULL
GROUP BY e.id, e.slug, e.title, e.status, e.starts_at, e.club_id, e.category_id, e.capacity, e.confirmed_count;

CREATE VIEW v_faculty_engagement AS
SELECT  f.id, f.code, f.name,
        COUNT(DISTINCT u.id)                           AS students,
        COUNT(r.id)                                    AS registrations,
        COALESCE(SUM(r.checked_in_at IS NOT NULL), 0)  AS attendances
FROM faculties f
LEFT JOIN users u         ON u.faculty_id = f.id AND u.role = 'student' AND u.deleted_at IS NULL
LEFT JOIN registrations r ON r.user_id = u.id AND r.status = 'confirmed'
GROUP BY f.id, f.code, f.name;
