-- Creates the first admin account. Login: admin / ChangeMe123  (you must pick a new password at first login).
-- Needs the pgcrypto extension, which schema.sql installs.
INSERT INTO users (username, password_hash, full_name, role, must_change)
VALUES ('admin', crypt('ChangeMe123', gen_salt('bf', 10)), 'Campus administrator', 'admin', TRUE)
ON CONFLICT (username) DO NOTHING;
