-- Least-privilege database account for the PHP application.
-- The app can read/write data but cannot DROP/ALTER tables or create users.
-- Run once as root. Replace the password and copy it into .env (DB_PASS).
CREATE USER IF NOT EXISTS 'taylors_app'@'localhost' IDENTIFIED BY 'change_me';
GRANT SELECT, INSERT, UPDATE, DELETE ON taylors_events.*      TO 'taylors_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON taylors_events_test.* TO 'taylors_app'@'localhost';
FLUSH PRIVILEGES;
