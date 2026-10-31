-- =============================================================================
--  Taylor's Event Management Platform (TEMP) - Database Schema
--  Engine   : MySQL 8.0.16+ (InnoDB). NOT MariaDB/XAMPP: we rely on enforced
--             CHECK constraints, native JSON and SELECT ... FOR UPDATE.
--  Charset  : utf8mb4 / utf8mb4_unicode_ci (full Unicode incl. emoji)
--  Timezone : All DATETIME values are stored in Asia/Kuala_Lumpur (+08:00).
--             The PHP layer runs "SET time_zone = '+08:00'" on every connection.
--
--  Session variables used by triggers (set by the PHP Database class):
--    @app_user_id   -> id of the authenticated actor (NULL for system/guest)
--    @app_ip        -> client IP address, written into audit_logs
--    @seed_mode     -> 1 only while loading seed.sql (relaxes "insert as draft"
--                      rule and skips trigger-based auditing of seed rows)
--
--  Defence in depth: business rules (venue clash, lifecycle transitions,
--  capacity) are enforced in PHP services AND re-checked here by triggers /
--  CHECK constraints, so even a buggy controller or a manual SQL edit cannot
--  corrupt the data.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';

DROP DATABASE IF EXISTS taylors_events;
CREATE DATABASE taylors_events
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE taylors_events;

-- -----------------------------------------------------------------------------
-- 1. faculties : Taylor's schools. Used for profiles and engagement analytics.
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
-- 2. users : one table, three roles (RBAC). Soft-deleted via deleted_at.
--    Brute-force protection columns live here (failed_login_count/locked_until).
-- -----------------------------------------------------------------------------
CREATE TABLE users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id          SMALLINT UNSIGNED NULL,
    student_number      VARCHAR(20)  NULL,                -- NULL for staff/admin
    full_name           VARCHAR(120) NOT NULL,
    email               VARCHAR(190) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,            -- Argon2id via password_hash()
    role                ENUM('student','organizer','admin') NOT NULL DEFAULT 'student',
    avatar_path         VARCHAR(255) NULL,
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
    KEY idx_users_role (role),
    KEY idx_users_faculty (faculty_id),
    CONSTRAINT fk_users_faculty FOREIGN KEY (faculty_id)
        REFERENCES faculties (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_users_email CHECK (email LIKE '%_@_%._%')
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 3. user_sessions : DB-backed PHP session store (custom SessionHandler).
--    Allows "log out all devices", admin session revocation and fingerprint
--    comparison. user_agent_hash = SHA-256 of the User-Agent header.
-- -----------------------------------------------------------------------------
CREATE TABLE user_sessions (
    id               VARCHAR(128)  NOT NULL,               -- PHP session id
    user_id          INT UNSIGNED  NULL,                   -- NULL = guest session
    ip_address       VARCHAR(45)   NOT NULL,               -- IPv4 or IPv6 text
    user_agent_hash  CHAR(64)      NOT NULL,
    payload          MEDIUMBLOB    NOT NULL,
    last_activity    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_activity (last_activity),             -- garbage collection
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 4. rate_limits : fixed-window counters keyed by "action:identifier"
--    e.g. 'login:203.0.113.5', 'register:email@x'. Blocks floods/brute force.
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
-- 5. venues : physical rooms on Lakeside Campus.
-- -----------------------------------------------------------------------------
CREATE TABLE venues (
    id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code           VARCHAR(20)  NOT NULL,
    name           VARCHAR(120) NOT NULL,
    building       VARCHAR(80)  NOT NULL,
    floor_label    VARCHAR(10)  NULL,
    capacity       SMALLINT UNSIGNED NOT NULL,
    venue_type     ENUM('hall','lecture_theatre','theatre','classroom','lab','studio','lounge','breakout') NOT NULL,
    capacity_verified TINYINT(1) NOT NULL DEFAULT 0,       -- 1 = published by the university
    has_projector  TINYINT(1) NOT NULL DEFAULT 0,
    is_accessible  TINYINT(1) NOT NULL DEFAULT 1,           -- wheelchair access
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_venues_code (code),
    KEY idx_venues_capacity (capacity),                     -- "alternative venue" search
    CONSTRAINT chk_venues_capacity CHECK (capacity > 0)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 6. event_categories
-- -----------------------------------------------------------------------------
CREATE TABLE event_categories (
    id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(60) NOT NULL,
    slug       VARCHAR(60) NOT NULL,
    color_hex  CHAR(7)     NOT NULL DEFAULT '#C8102E',
    icon       VARCHAR(40) NOT NULL DEFAULT 'calendar',
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    CONSTRAINT chk_categories_color CHECK (color_hex REGEXP '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 6a. clubs : the organisations that host events (student clubs, societies and
--     university offices such as Career Services). Events belong to a club,
--     not to a single person, so hosting survives committee turnover.
-- -----------------------------------------------------------------------------
CREATE TABLE clubs (
    id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(120) NOT NULL,
    slug         VARCHAR(120) NOT NULL,
    kind         ENUM('club','society','sports','office') NOT NULL DEFAULT 'club',
    contact_email VARCHAR(190) NULL,
    description  VARCHAR(500) NULL,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clubs_name (name),
    UNIQUE KEY uq_clubs_slug (slug)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 6b. club_members : which users may act for which club. Authorisation rule
--     (enforced in PHP): an organizer may create or edit an event only for a
--     club where their role is advisor, president or committee.
-- -----------------------------------------------------------------------------
CREATE TABLE club_members (
    club_id     SMALLINT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    role        ENUM('advisor','president','committee','member') NOT NULL DEFAULT 'member',
    joined_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (club_id, user_id),
    KEY idx_club_members_user (user_id),
    CONSTRAINT fk_club_members_club FOREIGN KEY (club_id)
        REFERENCES clubs (id) ON DELETE CASCADE,
    CONSTRAINT fk_club_members_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 6c. password_resets : single-use reset links. Only the SHA-256 hash of the
--     token is stored, so a database leak cannot be used to take over
--     accounts. Links expire after 30 minutes (expires_at) and are burned on
--     first use (used_at).
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
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_password_resets_expiry CHECK (expires_at > created_at)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 7. events : core entity, driven by a finite state machine:
--      draft -> pending_approval -> published -> ongoing -> completed -> archived
--      pending_approval -> draft   (admin rejects, rejection_reason required)
--      published        -> draft   (organizer unpublishes before it starts)
--    confirmed_count is a denormalised counter updated ONLY inside the booking
--    transaction while the event row is locked (SELECT ... FOR UPDATE).
--    The CHECK constraint makes overbooking physically impossible.
-- -----------------------------------------------------------------------------
CREATE TABLE events (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id               CHAR(36)     NOT NULL,          -- UUID exposed in URLs (no ID enumeration)
    organizer_id            INT UNSIGNED NOT NULL,          -- the member who created it
    club_id                 SMALLINT UNSIGNED NOT NULL,     -- the hosting club or office
    category_id             SMALLINT UNSIGNED NOT NULL,
    venue_id                SMALLINT UNSIGNED NOT NULL,
    title                   VARCHAR(150) NOT NULL,
    slug                    VARCHAR(170) NOT NULL,
    summary                 VARCHAR(300) NOT NULL,
    description             TEXT         NOT NULL,
    banner_path             VARCHAR(255) NULL,
    starts_at               DATETIME     NOT NULL,
    ends_at                 DATETIME     NOT NULL,
    registration_closes_at  DATETIME     NULL,              -- NULL = closes at starts_at
    capacity                SMALLINT UNSIGNED NOT NULL,
    confirmed_count         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status                  ENUM('draft','pending_approval','published','ongoing','completed','archived')
                            NOT NULL DEFAULT 'draft',
    rejection_reason        VARCHAR(500) NULL,
    approved_by             INT UNSIGNED NULL,
    approved_at             DATETIME     NULL,
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME     NULL,              -- soft delete (non-destructive)
    PRIMARY KEY (id),
    UNIQUE KEY uq_events_public_id (public_id),
    UNIQUE KEY uq_events_slug (slug),
    -- Venue-clash query: equality on venue_id, IN on status, range on starts_at.
    -- Column order follows that shape (verified with EXPLAIN ANALYZE, see
    -- docs/PERFORMANCE.md); ends_at is included so the check is index-only.
    KEY idx_events_venue_time (venue_id, status, starts_at, ends_at),
    -- Browse page: published + upcoming, sorted by date
    KEY idx_events_status_start (status, starts_at),
    KEY idx_events_organizer (organizer_id),
    KEY idx_events_club (club_id, starts_at),
    KEY idx_events_category (category_id),
    FULLTEXT KEY ftx_events_search (title, summary, description),
    CONSTRAINT fk_events_organizer FOREIGN KEY (organizer_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_events_club FOREIGN KEY (club_id)
        REFERENCES clubs (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_events_category FOREIGN KEY (category_id)
        REFERENCES event_categories (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_events_venue FOREIGN KEY (venue_id)
        REFERENCES venues (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_events_approver FOREIGN KEY (approved_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_events_time      CHECK (ends_at > starts_at),
    CONSTRAINT chk_events_capacity  CHECK (capacity > 0),
    CONSTRAINT chk_events_no_overbook CHECK (confirmed_count <= capacity)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 8. registrations : one row per (event, user). Re-joining after a
--    cancellation reuses the same row, so the UNIQUE key is never violated.
--    Waitlist order = queued_at ASC, id ASC (no fragile position numbers).
--    ticket_nonce is a random secret; the QR payload is
--    base64url(public_id|nonce) + "." + HMAC-SHA256(payload, APP_TICKET_KEY).
--    The signature is computed, never stored, so a DB leak cannot forge tickets.
-- -----------------------------------------------------------------------------
CREATE TABLE registrations (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id           INT UNSIGNED NOT NULL,
    user_id            INT UNSIGNED NOT NULL,
    status             ENUM('confirmed','waitlisted','cancelled') NOT NULL,
    ticket_nonce       CHAR(32)     NOT NULL,                -- bin2hex(random_bytes(16))
    idempotency_key    CHAR(36)     NULL,                    -- blocks double-click duplicates
    queued_at          DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3), -- ms precision for fair queue
    promoted_at        DATETIME     NULL,                    -- waitlist -> confirmed
    cancelled_at       DATETIME     NULL,
    checked_in_at      DATETIME     NULL,
    checked_in_by      INT UNSIGNED NULL,
    certificate_code   CHAR(12)     NULL,                    -- issued on check-in
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registrations_event_user (event_id, user_id),
    UNIQUE KEY uq_registrations_nonce (ticket_nonce),
    UNIQUE KEY uq_registrations_idempotency (idempotency_key),
    UNIQUE KEY uq_registrations_certificate (certificate_code),
    -- Waitlist promotion: next waitlisted user for an event
    KEY idx_registrations_queue (event_id, status, queued_at),
    KEY idx_registrations_user (user_id, status),
    CONSTRAINT fk_registrations_event FOREIGN KEY (event_id)
        REFERENCES events (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_registrations_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_registrations_checker FOREIGN KEY (checked_in_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    -- Only confirmed attendees can be checked in
    CONSTRAINT chk_registrations_checkin CHECK (checked_in_at IS NULL OR status = 'confirmed'),
    -- A certificate cannot exist without a check-in
    CONSTRAINT chk_registrations_cert CHECK (certificate_code IS NULL OR checked_in_at IS NOT NULL)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 9. notifications : in-app notifications, delivered to the browser by
--    short polling / Server-Sent Events from the PHP API.
-- -----------------------------------------------------------------------------
CREATE TABLE notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    type        ENUM('registration_confirmed','waitlisted','promoted_from_waitlist',
                     'registration_cancelled','event_approved','event_rejected',
                     'event_updated','event_reminder','certificate_issued') NOT NULL,
    title       VARCHAR(150) NOT NULL,
    body        VARCHAR(500) NOT NULL,
    link_url    VARCHAR(255) NULL,
    read_at     DATETIME     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Unread badge count + "fetch newer than id X" polling
    KEY idx_notifications_user_unread (user_id, read_at, id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 10. event_ratings : post-event feedback (attendees only, enforced by trigger)
-- -----------------------------------------------------------------------------
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
    CONSTRAINT fk_ratings_event FOREIGN KEY (event_id)
        REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_ratings_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_ratings_range CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 11. audit_logs : forensic, append-only trail with before/after snapshots.
--     Written by triggers (data changes) and by PHP (auth events: login,
--     logout, failed login, role change). UPDATE/DELETE are blocked.
-- -----------------------------------------------------------------------------
CREATE TABLE audit_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id     INT UNSIGNED NULL,                 -- NULL = system / guest
    action       VARCHAR(40)  NOT NULL,             -- e.g. event.update, auth.login_failed
    entity_type  VARCHAR(40)  NOT NULL,
    entity_id    INT UNSIGNED NULL,
    before_data  JSON         NULL,
    after_data   JSON         NULL,
    ip_address   VARCHAR(45)  NULL,
    created_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity_type, entity_id, created_at),
    KEY idx_audit_actor (actor_id, created_at),
    KEY idx_audit_action (action)
    -- No FK on actor_id on purpose: audit rows must survive user deletion.
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 12. idempotency_keys : generic double-submit protection for any POST
--     endpoint. The client sends an Idempotency-Key header (UUID v4); a replay
--     returns the stored response instead of executing the action twice.
-- -----------------------------------------------------------------------------
CREATE TABLE idempotency_keys (
    idem_key       CHAR(36)     NOT NULL,
    user_id        INT UNSIGNED NOT NULL,
    endpoint       VARCHAR(120) NOT NULL,
    response_code  SMALLINT UNSIGNED NOT NULL,
    response_body  JSON         NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (idem_key),
    KEY idx_idem_created (created_at),
    CONSTRAINT fk_idem_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- -----------------------------------------------------------------------------
-- 13. ticket_scans : every QR scan at the door, including rejected ones.
--     Gives organizers a door log, lets admins spot forgery attempts, and
--     backs the "already checked in" answer with evidence. Only a SHA-256 of
--     the raw payload is kept. Append-only, like audit_logs.
-- -----------------------------------------------------------------------------
CREATE TABLE ticket_scans (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NULL,               -- NULL when the ticket could not be matched
    scanned_by       INT UNSIGNED NULL,
    result           ENUM('admitted','duplicate','invalid_signature','wrong_event',
                          'not_confirmed','event_not_live') NOT NULL,
    payload_hash     CHAR(64)     NOT NULL,
    ip_address       VARCHAR(45)  NULL,
    created_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_scans_event_time (event_id, created_at),
    KEY idx_scans_result (result, created_at),
    KEY idx_scans_registration (registration_id),
    CONSTRAINT fk_scans_event FOREIGN KEY (event_id)
        REFERENCES events (id) ON DELETE RESTRICT,
    CONSTRAINT fk_scans_registration FOREIGN KEY (registration_id)
        REFERENCES registrations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_scans_user FOREIGN KEY (scanned_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 14. mail_outbox : every email the system sends (reset links, confirmations,
--     waitlist promotions). In local and demo mode the transport is 'log', so
--     emails are captured here and shown in the admin mailbox instead of
--     needing a real SMTP server. In production a worker sends and stamps
--     sent_at.
-- -----------------------------------------------------------------------------
CREATE TABLE mail_outbox (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    to_email         VARCHAR(190) NOT NULL,
    related_user_id  INT UNSIGNED NULL,
    template         VARCHAR(60)  NOT NULL,           -- e.g. password_reset, waitlist_promoted
    subject          VARCHAR(200) NOT NULL,
    body_text        TEXT         NOT NULL,
    transport        ENUM('log','smtp') NOT NULL DEFAULT 'log',
    sent_at          DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mail_user (related_user_id, created_at),
    KEY idx_mail_pending (sent_at, created_at),
    CONSTRAINT fk_mail_user FOREIGN KEY (related_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================================================
--  TRIGGERS
-- =============================================================================
DELIMITER $$

-- -----------------------------------------------------------------------------
-- events BEFORE INSERT
--  (a) New events must start as 'draft' (except while seeding demo data).
--  (b) Event capacity cannot exceed the venue's physical capacity.
--  (c) Venue clash guard for events inserted directly in an active state.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_events_bi
BEFORE INSERT ON events
FOR EACH ROW
BEGIN
    DECLARE v_capacity SMALLINT UNSIGNED;
    DECLARE v_clashes INT DEFAULT 0;

    IF NEW.status <> 'draft' AND COALESCE(@seed_mode, 0) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LIFECYCLE: new events must be created as draft';
    END IF;

    SELECT capacity INTO v_capacity FROM venues WHERE id = NEW.venue_id;
    IF NEW.capacity > v_capacity THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CAPACITY: event capacity exceeds venue capacity';
    END IF;

    -- Two intervals [s1,e1) and [s2,e2) overlap  <=>  s1 < e2 AND e1 > s2.
    -- Only events that actually reserve the room are considered.
    IF NEW.status IN ('pending_approval','published','ongoing') THEN
        SELECT COUNT(*) INTO v_clashes
          FROM events e
         WHERE e.venue_id = NEW.venue_id
           AND e.deleted_at IS NULL
           AND e.status IN ('pending_approval','published','ongoing')
           AND e.starts_at < NEW.ends_at
           AND e.ends_at   > NEW.starts_at;
        IF v_clashes > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'VENUE_CLASH: venue already booked for an overlapping time';
        END IF;
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- events BEFORE UPDATE
--  (a) Enforce the finite state machine (illegal transitions are rejected).
--  (b) Rejection requires a reason; approval stamps approved_at.
--  (c) Re-check venue clash + capacity whenever the event (re)enters an
--      active state or its venue/time changes.
--  NOTE: the trigger alone cannot stop two *concurrent* transactions racing
--  for the same slot; the PHP VenueService locks the venue row
--  (SELECT ... FROM venues WHERE id=? FOR UPDATE) to serialise those.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_events_bu
BEFORE UPDATE ON events
FOR EACH ROW
BEGIN
    DECLARE v_capacity SMALLINT UNSIGNED;
    DECLARE v_clashes INT DEFAULT 0;
    DECLARE v_ok TINYINT DEFAULT 0;

    IF NEW.status <> OLD.status THEN
        SET v_ok = CASE
            WHEN OLD.status = 'draft'            AND NEW.status = 'pending_approval' THEN 1
            WHEN OLD.status = 'pending_approval' AND NEW.status IN ('published','draft') THEN 1
            WHEN OLD.status = 'published'        AND NEW.status IN ('ongoing','draft') THEN 1
            WHEN OLD.status = 'ongoing'          AND NEW.status = 'completed' THEN 1
            WHEN OLD.status = 'completed'        AND NEW.status = 'archived' THEN 1
            ELSE 0 END;
        IF v_ok = 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'LIFECYCLE: illegal event status transition';
        END IF;

        IF OLD.status = 'pending_approval' AND NEW.status = 'draft'
           AND (NEW.rejection_reason IS NULL OR CHAR_LENGTH(TRIM(NEW.rejection_reason)) < 5) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'LIFECYCLE: a rejection reason is required';
        END IF;

        IF NEW.status = 'published' AND OLD.status = 'pending_approval' THEN
            SET NEW.approved_at = COALESCE(NEW.approved_at, NOW());
            SET NEW.rejection_reason = NULL;
        END IF;

        -- Unpublishing is only allowed while nobody holds a confirmed seat.
        IF OLD.status = 'published' AND NEW.status = 'draft' AND OLD.confirmed_count > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'LIFECYCLE: cannot unpublish an event with confirmed attendees';
        END IF;
    END IF;

    IF NEW.capacity <> OLD.capacity OR NEW.venue_id <> OLD.venue_id THEN
        SELECT capacity INTO v_capacity FROM venues WHERE id = NEW.venue_id;
        IF NEW.capacity > v_capacity THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CAPACITY: event capacity exceeds venue capacity';
        END IF;
    END IF;

    IF NEW.status IN ('pending_approval','published','ongoing')
       AND NEW.deleted_at IS NULL
       AND (NEW.status <> OLD.status OR NEW.venue_id <> OLD.venue_id
            OR NEW.starts_at <> OLD.starts_at OR NEW.ends_at <> OLD.ends_at) THEN
        SELECT COUNT(*) INTO v_clashes
          FROM events e
         WHERE e.venue_id = NEW.venue_id
           AND e.id <> NEW.id
           AND e.deleted_at IS NULL
           AND e.status IN ('pending_approval','published','ongoing')
           AND e.starts_at < NEW.ends_at
           AND e.ends_at   > NEW.starts_at;
        IF v_clashes > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'VENUE_CLASH: venue already booked for an overlapping time';
        END IF;
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- events AFTER INSERT / UPDATE : forensic audit with before/after JSON.
-- Soft delete (deleted_at set) is logged as event.delete.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_events_ai
AFTER INSERT ON events
FOR EACH ROW
BEGIN
    IF COALESCE(@seed_mode, 0) <> 1 THEN
        INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address)
        VALUES (@app_user_id, 'event.create', 'event', NEW.id, NULL,
                JSON_OBJECT('title', NEW.title, 'status', NEW.status, 'venue_id', NEW.venue_id,
                            'starts_at', NEW.starts_at, 'ends_at', NEW.ends_at,
                            'capacity', NEW.capacity, 'category_id', NEW.category_id,
                            'club_id', NEW.club_id),
                @app_ip);
    END IF;
END$$

CREATE TRIGGER trg_events_au
AFTER UPDATE ON events
FOR EACH ROW
BEGIN
    -- confirmed_count changes are booking activity, already audited on
    -- registrations; skip them here to keep the trail readable.
    IF COALESCE(@seed_mode, 0) <> 1 AND (
           NOT (OLD.title     <=> NEW.title)     OR NOT (OLD.status    <=> NEW.status)
        OR NOT (OLD.venue_id  <=> NEW.venue_id)  OR NOT (OLD.starts_at <=> NEW.starts_at)
        OR NOT (OLD.ends_at   <=> NEW.ends_at)   OR NOT (OLD.capacity  <=> NEW.capacity)
        OR NOT (OLD.summary   <=> NEW.summary)   OR NOT (OLD.description <=> NEW.description)
        OR NOT (OLD.category_id <=> NEW.category_id) OR NOT (OLD.deleted_at <=> NEW.deleted_at)) THEN
        INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address)
        VALUES (@app_user_id,
                CASE WHEN OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN 'event.delete'
                     WHEN OLD.status <> NEW.status THEN CONCAT('event.', NEW.status)
                     ELSE 'event.update' END,
                'event', NEW.id,
                JSON_OBJECT('title', OLD.title, 'status', OLD.status, 'venue_id', OLD.venue_id,
                            'starts_at', OLD.starts_at, 'ends_at', OLD.ends_at,
                            'capacity', OLD.capacity, 'category_id', OLD.category_id,
                            'deleted_at', OLD.deleted_at),
                JSON_OBJECT('title', NEW.title, 'status', NEW.status, 'venue_id', NEW.venue_id,
                            'starts_at', NEW.starts_at, 'ends_at', NEW.ends_at,
                            'capacity', NEW.capacity, 'category_id', NEW.category_id,
                            'deleted_at', NEW.deleted_at),
                @app_ip);
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- registrations AFTER INSERT / UPDATE : audit every booking state change.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_registrations_ai
AFTER INSERT ON registrations
FOR EACH ROW
BEGIN
    IF COALESCE(@seed_mode, 0) <> 1 THEN
        INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address)
        VALUES (@app_user_id, CONCAT('registration.', NEW.status), 'registration', NEW.id, NULL,
                JSON_OBJECT('event_id', NEW.event_id, 'user_id', NEW.user_id, 'status', NEW.status),
                @app_ip);
    END IF;
END$$

CREATE TRIGGER trg_registrations_au
AFTER UPDATE ON registrations
FOR EACH ROW
BEGIN
    IF COALESCE(@seed_mode, 0) <> 1 AND (
           NOT (OLD.status <=> NEW.status) OR NOT (OLD.checked_in_at <=> NEW.checked_in_at)) THEN
        INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address)
        VALUES (@app_user_id,
                CASE WHEN OLD.checked_in_at IS NULL AND NEW.checked_in_at IS NOT NULL
                          THEN 'registration.checkin'
                     WHEN OLD.status = 'waitlisted' AND NEW.status = 'confirmed'
                          THEN 'registration.promoted'
                     ELSE CONCAT('registration.', NEW.status) END,
                'registration', NEW.id,
                JSON_OBJECT('status', OLD.status, 'checked_in_at', OLD.checked_in_at),
                JSON_OBJECT('status', NEW.status, 'checked_in_at', NEW.checked_in_at),
                @app_ip);
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- users AFTER UPDATE : audit role changes and (de)activation only.
-- Passwords and hashes are never written into the audit trail.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_users_au
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    IF COALESCE(@seed_mode, 0) <> 1 AND (
           NOT (OLD.role <=> NEW.role) OR NOT (OLD.is_active <=> NEW.is_active)
        OR NOT (OLD.deleted_at <=> NEW.deleted_at)) THEN
        INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address)
        VALUES (@app_user_id, 'user.update', 'user', NEW.id,
                JSON_OBJECT('role', OLD.role, 'is_active', OLD.is_active, 'deleted_at', OLD.deleted_at),
                JSON_OBJECT('role', NEW.role, 'is_active', NEW.is_active, 'deleted_at', NEW.deleted_at),
                @app_ip);
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- event_ratings BEFORE INSERT : only checked-in attendees of a completed or
-- archived event may rate it.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_ratings_bi
BEFORE INSERT ON event_ratings
FOR EACH ROW
BEGIN
    DECLARE v_attended INT DEFAULT 0;
    SELECT COUNT(*) INTO v_attended
      FROM registrations r
      JOIN events e ON e.id = r.event_id
     WHERE r.event_id = NEW.event_id
       AND r.user_id  = NEW.user_id
       AND r.checked_in_at IS NOT NULL
       AND e.status IN ('completed','archived');
    IF v_attended = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'RATING: only checked-in attendees of a finished event can rate it';
    END IF;
END$$

-- -----------------------------------------------------------------------------
-- audit_logs is append-only: tampering with history is rejected.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_audit_no_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AUDIT: audit log is append-only';
END$$

CREATE TRIGGER trg_audit_no_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AUDIT: audit log is append-only';
END$$

-- ticket_scans is append-only: the door log cannot be rewritten.
CREATE TRIGGER trg_scans_no_update
BEFORE UPDATE ON ticket_scans
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SCANS: scan log is append-only';
END$$

CREATE TRIGGER trg_scans_no_delete
BEFORE DELETE ON ticket_scans
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SCANS: scan log is append-only';
END$$

DELIMITER ;


-- =============================================================================
--  VIEWS (read models for the analytics dashboard)
-- =============================================================================

-- Per-event KPIs: fill rate and attendance (show-up) rate.
CREATE VIEW v_event_stats AS
SELECT  e.id,
        e.public_id,
        e.title,
        e.status,
        e.starts_at,
        c.name                                         AS category,
        cl.name                                        AS club,
        v.name                                         AS venue,
        e.capacity,
        e.confirmed_count,
        COALESCE(SUM(r.status = 'waitlisted'), 0)      AS waitlisted_count,
        COALESCE(SUM(r.checked_in_at IS NOT NULL), 0) AS attended_count,
        ROUND(100 * e.confirmed_count / e.capacity, 1) AS fill_rate_pct,
        -- Show-up rate is only meaningful once check-in has started.
        CASE WHEN e.status IN ('ongoing','completed','archived')
             THEN ROUND(100 * SUM(r.checked_in_at IS NOT NULL)
                        / NULLIF(e.confirmed_count, 0), 1)
        END                                            AS attendance_rate_pct,
        (SELECT ROUND(AVG(er.rating), 2)
           FROM event_ratings er WHERE er.event_id = e.id) AS avg_rating
FROM events e
JOIN event_categories c ON c.id = e.category_id
JOIN venues v           ON v.id = e.venue_id
JOIN clubs cl           ON cl.id = e.club_id
LEFT JOIN registrations r ON r.event_id = e.id
WHERE e.deleted_at IS NULL
GROUP BY e.id, e.public_id, e.title, e.status, e.starts_at, c.name, cl.name, v.name,
         e.capacity, e.confirmed_count;

-- Faculty engagement: registrations and show-up rate per school.
-- Attendance rate uses FINISHED events only; otherwise future bookings
-- (not yet attendable) would drag the rate down artificially.
CREATE VIEW v_faculty_engagement AS
SELECT  f.id,
        f.code,
        f.name,
        COUNT(DISTINCT u.id)                                           AS students,
        COUNT(r.id)                                                    AS registrations,
        COALESCE(SUM(e.status IN ('completed','archived')), 0)         AS finished_registrations,
        COALESCE(SUM(r.checked_in_at IS NOT NULL
                     AND e.status IN ('completed','archived')), 0)     AS attendances,
        ROUND(100 * SUM(r.checked_in_at IS NOT NULL AND e.status IN ('completed','archived'))
                  / NULLIF(SUM(e.status IN ('completed','archived')), 0), 1) AS attendance_rate_pct
FROM faculties f
LEFT JOIN users u          ON u.faculty_id = f.id AND u.role = 'student' AND u.deleted_at IS NULL
LEFT JOIN registrations r  ON r.user_id = u.id AND r.status = 'confirmed'
LEFT JOIN events e         ON e.id = r.event_id AND e.deleted_at IS NULL
GROUP BY f.id, f.code, f.name;
