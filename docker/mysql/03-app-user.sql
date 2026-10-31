-- Docker only: the PHP container connects over the network, so the app user
-- is allowed from any container host ('%') instead of 'localhost'.
CREATE USER IF NOT EXISTS 'taylors_app'@'%' IDENTIFIED BY 'taylors_dev_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON taylors_events.*      TO 'taylors_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON taylors_events_test.* TO 'taylors_app'@'%';
FLUSH PRIVILEGES;
