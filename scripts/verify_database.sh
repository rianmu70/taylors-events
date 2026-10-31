#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Database verification suite. Run after loading schema.sql + seed.sql.
# Every check prints PASS or FAIL. Usage:  bash scripts/verify_database.sh [mysql args]
# Example: bash scripts/verify_database.sh -uroot -p
# NOTE: checks 1-11 do not change data (failed statements roll back); checks
# 9, 11 and 12 modify rows, so reload the seed before a demo.
# -----------------------------------------------------------------------------
set -u
MYSQL="mysql $* -N -B taylors_events"
pass=0; fail=0
id_of(){ $MYSQL -e "SELECT id FROM events WHERE slug LIKE '$1%' LIMIT 1"; }
expect_error(){ # name, expected text, sql
  out=$($MYSQL -e "SET time_zone='+08:00'; SET @app_user_id=3; SET @app_ip='10.0.0.1'; $3" 2>&1)
  if echo "$out" | grep -q "$2"; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1 :: $out"; fail=$((fail+1)); fi
}
expect_value(){ # name, expected, sql
  out=$($MYSQL -e "SET time_zone='+08:00'; SET @app_user_id=1; SET @app_ip='10.0.0.1'; $3" 2>&1 | tail -1)
  if [ "$out" = "$2" ]; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1 :: got '$out' expected '$2'"; fail=$((fail+1)); fi
}
JAM=$(id_of data-science); LAST=$(id_of cloud-and-devops); PEND=$(id_of robotics); DONE=$(id_of startup-pitch)

expect_value "seed: confirmed_count matches registrations" "0" \
  "SELECT COUNT(*) FROM events e WHERE e.confirmed_count <> (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status='confirmed');"
expect_value "seed: no overlapping active bookings per venue" "0" \
  "SELECT COUNT(*) FROM events a JOIN events b ON a.venue_id=b.venue_id AND a.id<b.id WHERE a.status IN ('pending_approval','published','ongoing') AND b.status IN ('pending_approval','published','ongoing') AND a.deleted_at IS NULL AND b.deleted_at IS NULL AND a.starts_at<b.ends_at AND a.ends_at>b.starts_at;"
expect_value "seed: every event belongs to a club" "0" "SELECT COUNT(*) FROM events WHERE club_id IS NULL;"
expect_error "new event cannot start as published" "LIFECYCLE: new events" \
  "INSERT INTO events (public_id,organizer_id,club_id,category_id,venue_id,title,slug,summary,description,starts_at,ends_at,capacity,status) VALUES (UUID(),3,3,1,9,'x','x-1','s','d',NOW()+INTERVAL 40 DAY,NOW()+INTERVAL 40 DAY+INTERVAL 1 HOUR,10,'published');"
expect_error "capacity cannot exceed venue" "CAPACITY" \
  "INSERT INTO events (public_id,organizer_id,club_id,category_id,venue_id,title,slug,summary,description,starts_at,ends_at,capacity) VALUES (UUID(),3,3,1,9,'x','x-2','s','d',NOW()+INTERVAL 40 DAY,NOW()+INTERVAL 40 DAY+INTERVAL 1 HOUR,999);"
expect_error "submitting a clashing draft is blocked" "VENUE_CLASH" "UPDATE events SET status='pending_approval' WHERE id=$JAM;"
expect_error "draft cannot skip approval" "illegal event status" "UPDATE events SET status='published' WHERE id=$JAM;"
expect_error "rejection needs a reason" "rejection reason" "UPDATE events SET status='draft' WHERE id=$PEND;"
expect_error "overbooking is impossible" "chk_events_no_overbook" "UPDATE events SET confirmed_count=capacity+1 WHERE id=$LAST;"
expect_error "audit log is append-only" "append-only" "DELETE FROM audit_logs LIMIT 1;"
expect_error "only attendees can rate" "RATING" "INSERT INTO event_ratings (event_id,user_id,rating) VALUES ($LAST, 20, 5);"
expect_error "finished event cannot be reopened" "illegal event status" "UPDATE events SET status='published' WHERE id=$DONE;"
expect_error "reset token must expire after creation" "chk_password_resets_expiry" \
  "INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (11, SHA2('t',256), NOW() - INTERVAL 1 MINUTE);"
expect_value "approval is audited with before/after" "event.published|pending_approval|published" \
  "UPDATE events SET status='published', approved_by=1 WHERE id=$PEND; SELECT CONCAT(action,'|',JSON_UNQUOTE(before_data->'$.status'),'|',JSON_UNQUOTE(after_data->'$.status')) FROM audit_logs ORDER BY id DESC LIMIT 1;"
expect_value "soft delete is audited" "event.delete" \
  "UPDATE events SET deleted_at=NOW() WHERE id=$PEND; SELECT action FROM audit_logs ORDER BY id DESC LIMIT 1;"

expect_value "every check-in has an admitted door scan" "0" \
  "SELECT COUNT(*) FROM registrations r WHERE r.checked_in_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ticket_scans t WHERE t.registration_id=r.id AND t.result='admitted');"
expect_error "door scan log is append-only" "SCANS: scan log is append-only" "DELETE FROM ticket_scans LIMIT 1;"
expect_error "door scan result must be a known outcome" "Data truncated\\|Incorrect\\|1265" \
  "INSERT INTO ticket_scans (event_id,result,payload_hash) VALUES ($LAST,'maybe',SHA2('x',256));"

# Concurrency: 20 parallel transactions race for the last seat.
USERS=$($MYSQL -e "SELECT id FROM users WHERE role='student' AND id NOT IN (SELECT user_id FROM registrations WHERE event_id=$LAST) LIMIT 20")
for U in $USERS; do
  $MYSQL -e "START TRANSACTION;
    SELECT capacity - confirmed_count INTO @free FROM events WHERE id=$LAST FOR UPDATE;
    DO SLEEP(0.05);
    SET @st = IF(@free > 0, 'confirmed', 'waitlisted');
    INSERT INTO registrations (event_id,user_id,status,ticket_nonce) VALUES ($LAST,$U,@st,REPLACE(UUID(),'-',''));
    UPDATE events SET confirmed_count = confirmed_count + (@st='confirmed') WHERE id=$LAST;
    COMMIT;" &
done; wait
expect_value "20 parallel bookings: exactly 1 wins the last seat" "1|19|30" \
  "SELECT CONCAT((SELECT COUNT(*) FROM registrations WHERE event_id=$LAST AND status='confirmed')-29,'|',(SELECT COUNT(*) FROM registrations WHERE event_id=$LAST AND status='waitlisted'),'|',(SELECT confirmed_count FROM events WHERE id=$LAST));"

echo "----"; echo "$pass passed, $fail failed"; [ $fail -eq 0 ]
