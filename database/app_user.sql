-- =============================================================================
--  Least privilege account for the PHP application. Run once as root, after
--  schema.sql. scripts/setup.sh does this for you and writes the password
--  into .env.
--
--  The account can read and write rows and nothing else. It has no DROP,
--  ALTER, CREATE, TRIGGER or EXECUTE privilege, so even a full compromise of
--  the web application cannot remove the triggers that protect the audit log,
--  the venue calendar or the seat count.
-- =============================================================================
CREATE USER IF NOT EXISTS 'taylors_app'@'localhost' IDENTIFIED BY 'change_me';
CREATE USER IF NOT EXISTS 'taylors_app'@'%'         IDENTIFIED BY 'change_me';
GRANT SELECT, INSERT, UPDATE, DELETE ON taylors_events.* TO 'taylors_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON taylors_events.* TO 'taylors_app'@'%';
FLUSH PRIVILEGES;
