# Checkmate backend

PHP API for the Checkmate Android app. Implements `docs/API.md` §1 (authentication)
and §2 (health) end-to-end, ships the full database schema for §3–§9, and runs with
**zero third-party dependencies** — no Composer packages, no framework, no ORM.

- PHP **8.1+** CLI (developed and tested on 8.5.1)
- MariaDB **10.6+ / 13.x** (a private instance lives in `backend/var/db`)
- `spl_autoload_register` PSR-4 autoloading — `composer.json` is a *manifest only*,
  nothing is installed from it

---

## Layout

```
backend/
├── bin/
│   ├── dev.sh              start private DB + migrations + API on :8080
│   ├── test.sh             full test cycle (DB → migrate → API → suite)
│   ├── lib.sh              shared DB lifecycle helpers (sourced by both)
│   ├── migrate.php         migration runner (up-files only, idempotent)
│   └── front-controller.php  canonical HTTP entry (used as the php -S router)
├── config/config.php       env → config array (env > .env > defaults)
├── migrations/NNNN_*.sql   schema; applied in order, recorded in schema_migrations
├── public/                 web root (.htaccess, index.php)
├── routes/api.php          route table (method, path, handler, meta)
├── src/
│   ├── App.php             HTTP kernel: dispatch → middleware → envelope
│   ├── Controllers/        AuthController, HealthController, AccountController
│   ├── Database/           Connection (PDO), Migrator
│   ├── Http/               Request, Response, JsonResponse, ApiException, Router
│   ├── Middleware/         request-id, security headers, CORS, rate limit, auth
│   ├── Services/           AuthService, TokenService, MailService, RateLimiter
│   └── Support/            Clock, Json, Log, Validator
├── tests/api.php           docs/API.md §1+§2 suite (28 tests, run by bin/test.sh)
├── var/                    runtime state: db/, run/, log/ (git-ignored)
├── .env.example            settings template (copy to .env)
└── composer.json           metadata + PSR-4 map (no packages)
```

## Configure

```bash
cp backend/.env.example backend/.env      # then set a real DB_PASSWORD
chmod 600 backend/.env                    # .env is git-ignored
```

`bin/lib.sh` generates `.env` (with a random DB password) automatically when it is
missing. Setting precedence: **process environment > `.env` > built-in default**,
so tests and containers can override any key without touching the file.

Key settings:

| Key | Meaning |
| --- | --- |
| `APP_ENV` | `development` (mail previews, dev hints) or `production` |
| `DB_HOST` / `DB_PORT` / `DB_SOCKET` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | private MariaDB on `127.0.0.1:3307` |
| `BODY_MAX_BYTES` | request body cap (default 65536 = 64 KB) |
| `MAIL_TRANSPORT` | `null`/`file` → dev-only file preview; anything else → unconfigured |
| `RATE_LIMIT_LOGIN` etc. | fixed-window limits per route group |

## Run

```bash
backend/bin/dev.sh            # DB + migrations + API at http://127.0.0.1:8080
```

`dev.sh` starts MariaDB on `127.0.0.1:3307` (socket `backend/var/run/mysqld.sock`)
if it is not already up, applies pending migrations, re-pins `public/index.php` to
`bin/front-controller.php`, and serves the API with `php -S`.

Manual equivalent:

```bash
source backend/bin/lib.sh; BACKEND_DIR=$PWD/backend backend_up   # DB + migrations
php -S 127.0.0.1:8080 -t backend/public backend/bin/front-controller.php
```

## Test

```bash
backend/bin/test.sh           # exit 0 = all green, 1 = failures
```

The harness boots/uses the private DB, migrates it, starts an API server on
`127.0.0.1:8081`, runs `tests/api.php`, then stops the server. It also spawns a
second, production-mode server to prove `503 MAIL_NOT_CONFIGURED` behaviour.

```bash
TEST_BASE_URL=http://127.0.0.1:8081 php backend/tests/api.php   # suite only
```

## Migrate

```bash
php backend/bin/migrate.php            # apply pending NNNN_*.sql files
php backend/bin/migrate.php status     # applied / pending / drift
php backend/bin/migrate.php rollback   # needs NNNN_*.down.sql (step=1 by default)
```

Migrations are idempotent (`CREATE ... IF NOT EXISTS`), run without an enclosing
transaction (MariaDB DDL auto-commits), and only `NNNN_*.sql` *up* files are
applied — `*.down.sql` files are informational.

## Deploy

1. Copy the tree; do **not** ship `backend/.env` or `backend/var/` — pass settings
   as environment variables instead.
2. Point the web server at `backend/public/index.php` (`try_files $uri $uri/ /index.php;`),
   or run `php -S` behind a reverse proxy. `bin/dev.sh` regenerates `public/index.php`
   from `bin/front-controller.php`; keep them byte-identical.
3. TLS terminates at the reverse proxy — this process speaks plain HTTP on loopback.
4. Run migrations once per release: `php backend/bin/migrate.php`.
5. Never enable `APP_ENV=development` in production: it exposes `dev_preview` mail
   bodies (which contain single-use tokens).

## Mail

`MailTransport` is an interface; the shipped implementations are:

- `NullMailTransport` — development only: appends the message to
  `backend/var/log/mail-dev.log` and returns it as `dev_preview`. Outside
  development it reports *not configured*.
- `NotConfiguredTransport` — always *not configured*.

There is **no SMTP delivery** in this environment. Endpoints that must send e-mail
answer `503 MAIL_NOT_CONFIGURED` in production instead of pretending a message was
sent; in development they return `200 { ok, dev_preview }`.

## Security notes

- Passwords: `PASSWORD_ARGON2ID` when the build provides it, otherwise the
  documented `PASSWORD_BCRYPT` fallback (this PHP build has no argon2).
- Tokens: opaque 32-byte base64url, stored only as SHA-256; access 15 min,
  refresh 30 d, rotated on use, family revoked on reuse.
- PDO prepared statements everywhere; generic auth errors; `INTERNAL_ERROR`
  never leaks SQL, paths or credentials; multi-row writes run in transactions.
- Passwords, tokens and e-mail addresses are never logged.
