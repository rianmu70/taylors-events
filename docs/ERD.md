# Entity Relationship Diagram

```mermaid
erDiagram
    faculties ||--o{ users : "has"
    users ||--o{ user_sessions : "owns"
    users ||--o{ events : "organizes"
    users ||--o{ registrations : "books"
    users ||--o{ notifications : "receives"
    users ||--o{ event_ratings : "writes"
    users ||--o{ idempotency_keys : "sends"
    users ||--o{ password_resets : "requests"
    clubs ||--o{ club_members : "has"
    users ||--o{ club_members : "joins"
    clubs ||--o{ events : "hosts"
    events ||--o{ ticket_scans : "logs"
    registrations ||--o{ ticket_scans : "scanned as"
    users ||--o{ mail_outbox : "receives"
    venues ||--o{ events : "hosts"
    event_categories ||--o{ events : "classifies"
    events ||--o{ registrations : "has"
    events ||--o{ event_ratings : "receives"

    faculties { smallint id PK
                varchar code UK
                varchar name }
    users { int id PK
            smallint faculty_id FK
            varchar email UK
            varchar password_hash
            enum role
            tinyint failed_login_count
            datetime locked_until
            datetime deleted_at }
    user_sessions { varchar id PK
                    int user_id FK
                    char user_agent_hash
                    mediumblob payload }
    rate_limits { varchar bucket_key PK
                  int hits
                  datetime blocked_until }
    venues { smallint id PK
             varchar code UK
             smallint capacity
             enum venue_type }
    event_categories { smallint id PK
                       varchar slug UK }
    clubs { smallint id PK
            varchar name UK
            enum kind }
    club_members { smallint club_id PK
                   int user_id PK
                   enum role }
    password_resets { int id PK
                      int user_id FK
                      char token_hash UK
                      datetime expires_at
                      datetime used_at }
    events { int id PK
             char public_id UK
             int organizer_id FK
             smallint club_id FK
             smallint venue_id FK
             smallint category_id FK
             datetime starts_at
             datetime ends_at
             smallint capacity
             smallint confirmed_count
             enum status
             datetime deleted_at }
    registrations { int id PK
                    int event_id FK
                    int user_id FK
                    enum status
                    char ticket_nonce UK
                    datetime queued_at
                    datetime checked_in_at
                    char certificate_code UK }
    notifications { bigint id PK
                    int user_id FK
                    enum type
                    datetime read_at }
    event_ratings { int id PK
                    int event_id FK
                    int user_id FK
                    tinyint rating }
    audit_logs { bigint id PK
                 int actor_id
                 varchar action
                 json before_data
                 json after_data }
    ticket_scans { bigint id PK
                   int event_id FK
                   int registration_id FK
                   enum result
                   char payload_hash }
    mail_outbox { bigint id PK
                  varchar to_email
                  varchar template
                  datetime sent_at }
    idempotency_keys { char idem_key PK
                       int user_id FK
                       json response_body }
```

## Key design decisions

| Decision | Reason |
|---|---|
| `confirmed_count` counter + `CHECK (confirmed_count <= capacity)` | Booking locks one event row (`FOR UPDATE`) instead of counting rows; the CHECK makes overbooking impossible even if application code is wrong |
| Waitlist ordered by `queued_at` (ms precision) | No position numbers to renumber after cancellations |
| One registration row per (event, user) | Re-joining reuses the row; UNIQUE key blocks duplicates |
| QR signature computed, not stored | A database leak cannot be used to forge tickets |
| `public_id` UUID in URLs | Prevents enumerating events by incrementing IDs |
| Triggers re-check lifecycle, capacity and venue clashes | Defence in depth behind the PHP services |
| Audit trigger reads `@app_user_id` / `@app_ip` | Every change records who and from where, with before/after JSON |
| `audit_logs` blocks UPDATE/DELETE | Tamper-evident history |
| No FK on `audit_logs.actor_id` | History survives user deletion |
| Least-privilege DB user | The app account cannot DROP or ALTER tables |
| Events belong to a club, with `club_members` roles | Hosting survives committee turnover; only advisors, presidents and committee members can publish for a club |
| Reset tokens stored as SHA-256 hashes, 30 minute expiry, single use | A database leak cannot be turned into account takeover |
| Every door scan logged, including rejected ones, append-only | Forgery attempts and duplicate entries leave evidence |
| Emails captured in `mail_outbox` | Reset and confirmation flows can be demonstrated without an SMTP server |
| Index column order checked with EXPLAIN ANALYZE | See `docs/PERFORMANCE.md` |

## Verified behaviour (MySQL 8.0.46)

All 19 checks in `scripts/verify_database.sh` pass, including:

| Test | Result |
|---|---|
| Insert event directly as published | Rejected (LIFECYCLE) |
| Event capacity above venue capacity | Rejected (CAPACITY) |
| Submit draft overlapping a booked venue | Rejected (VENUE_CLASH) |
| draft to published, skipping approval | Rejected (LIFECYCLE) |
| Reject without a reason | Rejected |
| Force confirmed_count above capacity | Rejected (CHECK) |
| Delete an audit row | Rejected (AUDIT) |
| Rating by a non-attendee | Rejected (RATING) |
| Reset token that expires before it is created | Rejected (CHECK) |
| Delete a door scan record | Rejected (SCANS) |
| Every check-in has an admitted door scan | Confirmed |
| Approve, soft delete | Allowed, audited with before/after JSON |
| 20 parallel bookings for the last seat | 1 confirmed, 19 waitlisted, count = capacity |
| App DB user runs DROP TABLE | Denied |
