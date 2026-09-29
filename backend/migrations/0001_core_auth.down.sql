-- down 0001: reverse of 0001_core_auth.sql (children before parents).
-- NOTE: targets the 0001 schema shipped by the concurrent agent (sessions …);
-- IF EXISTS keeps it safe against earlier revisions of this file.
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS email_verification_tokens;
DROP TABLE IF EXISTS auth_tokens;
DROP TABLE IF EXISTS users;
