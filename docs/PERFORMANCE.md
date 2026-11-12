# Performance results

Measured, not estimated. Every number below comes from `EXPLAIN ANALYZE`, which
reports the time MySQL actually spent, and can be reproduced with one command:

```bash
php scripts/perf/benchmark.php
```

## Method

| | |
|---|---|
| Dataset | 200,000 events over four years in 60 venues, 1,000,000 registrations, 20,000 users |
| How it is built | A separate database, `taylors_events_perf`, created from the tables in `schema.sql` and filled with recursive CTEs. The demo database is not touched. |
| What is compared | Each query as the application runs it, and the same query with `IGNORE INDEX`, so the only difference is the index |
| Timing | Median of five runs of `EXPLAIN ANALYZE`, total "actual time" of the plan |
| Environment | MySQL 8.0.46, PHP 8.3.6, Ubuntu 24.04, 2 vCPU, default 128 MB buffer pool |

The demo data (24 events) is far too small to show any difference, which is why
a synthetic dataset about a thousand times larger than a real semester is used.

## Results

| Query | Index | With (ms) | Without (ms) | Speed-up |
|---|---|---:|---:|---:|
| Venue clash check (guarantee 2) | `idx_events_venue_time` | 0.042 | 4.090 | 98x |
| Browse page, first 8 upcoming events | `idx_events_status_start` | 10.500 | 79.800 | 8x |
| Clock: published events that have started | `idx_events_status_start` | 0.013 | 58.100 | 4648x |
| Next person on a waitlist | `idx_registrations_queue` | 0.014 | 303.000 | 22279x |
| My registrations | `idx_registrations_user` | 0.014 | 243.000 | 17737x |

Timings under 0.05 ms move by a few microseconds between runs, so the largest
speed-up figures vary from run to run. The order of magnitude does not.

## What the numbers mean

**Venue clash check.** This query runs inside the trigger on every submission
and approval, while a lock is held, so it has to be fast. The index is
`(venue_id, status, starts_at, ends_at)`: equality on the venue, the three
statuses that hold a room, then a range on the start time. The plan is an index
range scan that reads only the rows for one room that start before the proposed
end:

```
-> Index range scan on e using idx_events_venue_time over
   (venue_id = 8 AND status = 'pending_approval' AND starts_at < ...) OR
   (venue_id = 8 AND status = 'published'        AND starts_at < ...) OR
   (venue_id = 8 AND status = 'ongoing'          AND starts_at < ...)
   (actual time=0.0493..0.0493 rows=0 loops=1)
```

Without the index MySQL falls back to the foreign key index on `venue_id` and
filters about 3,200 rows for that room: 4 ms instead of 0.04 ms, and that time
would be spent holding a lock.

**Browse page.** The slowest of the indexed queries, at about 10 ms. The index
finds the 8,000 open events at once, but `status IN ('published','ongoing')`
is two ranges, so MySQL still sorts those rows by start time to find the first
eight. On real data, where a few dozen events are open, this is far below a
millisecond. If the number of open events ever reached the thousands, the fix
would be to query the two statuses separately and merge, which lets the index
return rows already in order.

**The clock.** The lifecycle middleware asks for published events whose start
time has passed on a timer. With the index that is 13 microseconds, so running
it on ordinary page requests instead of installing a cron job costs nothing.

**Waitlist and My registrations.** Both would be full scans of a million rows,
a quarter of a second each, without their indexes. Promotion from the waitlist
happens inside the booking lock, so 0.014 ms against 303 ms is the difference
between a queue that moves and one that stalls.

## A design the measurement rejected

The first version of the schema had a `FULLTEXT` index on
`(title, summary, description)` for the search box. Measured on the same
dataset, searching for "robotics" among open events:

| Approach | Time (ms) |
|---|---:|
| `LIKE '%robotics%'` on the three columns, after the status index | 14.7 |
| `MATCH ... AGAINST ('+robotics*' IN BOOLEAN MODE)` | 49.7 |

The full-text index finds every matching document in the whole table, including
the 96% that are finished or drafts, and only then applies the status filter.
The `LIKE` version lets `idx_events_status_start` cut the table down to open
events first and scans only those. Full-text was three times slower, costs an
extra index to maintain on every write, and cannot match part of a word. It was
removed, and the search uses `LIKE` on open events only
(`EventRepository::browse`).

## Concurrency cost

The booking transaction holds a row lock on one event from start to commit.
`tests/ConcurrencyTest.php` releases 20 processes at the same instant against
one seat. All 20 finish in well under a second in total, including the time to
fork and connect, and exactly one is confirmed. A lock per event, not per
table, means bookings for different events never wait for each other.
