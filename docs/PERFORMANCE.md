# Index performance

Measured with `scripts/perf/run_performance_test.sh`, which builds a separate
database (`taylors_events_perf`) and never touches demo data.

Dataset: 20,330 users, 3,024 events, 121,972 registrations, 150,006
notifications. MySQL 8.0.46, single run per query, `EXPLAIN ANALYZE` actual
time. Absolute times depend on the machine; the ratio is what matters.

| Query used by the app | Index | With index | Without | Faster by |
|---|---|---|---|---|
| Browse upcoming events | `idx_events_status_start` | 0.105 ms | 1.77 ms | about 17x |
| Venue clash check | `idx_events_venue_time` | 0.036 ms | 1.37 ms | about 38x |
| Next person on the waitlist | `idx_registrations_queue` | 0.016 ms | 30.0 ms | about 1,900x |
| My tickets | `idx_registrations_user` | 0.017 ms | 26.3 ms | about 1,500x |
| Unread notification badge | `idx_notifications_user_unread` | 0.020 ms | 33.6 ms | about 1,700x |

Without an index each of these becomes a full table scan, so the gap grows
with every new registration. With the index MySQL reads only the matching
rows, and three of the five queries are answered from the index alone
("covering index lookup"), without touching the table.

## A finding from this test

The first version of the venue clash index was `(venue_id, starts_at, ends_at)`.
EXPLAIN showed MySQL ignored it and used the status index instead, because the
query filters on `status IN (...)` as well. The index was reordered to
`(venue_id, status, starts_at, ends_at)`, which matches the query shape, and
MySQL now uses it.
