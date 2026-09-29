# Checkmate API v1 (REST)

Base URL (development): `http://10.0.2.2:8080/api/v1` (emulator → host) or `http://127.0.0.1:8080/api/v1`
Base URL (production): `https://<host>/api/v1` — **HTTPS mandatory** (the Android network security config refuses cleartext except loopback).

All responses use this envelope:

```json
{ "success": true,  "data": { }, "error": null }
{ "success": false, "data": null, "error": { "code": "VALIDATION_ERROR", "message": "The submitted request is invalid." } }
```

Common error codes: `VALIDATION_ERROR`, `UNAUTHORIZED`, `FORBIDDEN`, `NOT_FOUND`,
`CONFLICT`, `RATE_LIMITED`, `INTERNAL_ERROR`, `EMAIL_TAKEN`, `INVALID_CREDENTIALS`,
`TOKEN_EXPIRED`, `TOKEN_INVALID`, `EMAIL_NOT_VERIFIED`, `MATCH_NOT_FOUND`,
`ILLEGAL_MOVE`, `PROTOCOL_ERROR`, `RESULT_REJECTED`.

Headers: `Content-Type: application/json`, `X-Request-Id` (echoed on every response,
also returned as `X-Request-Id`), `Authorization: Bearer <access_token>` where marked **[auth]**.

Rate limits (per IP + route): login 10/min, register 5/min, verify 10/min,
resend 3/min, forgot 3/min, reset 5/min, everything else 120/min.
Exceeding returns `429` + `RATE_LIMITED` + `Retry-After`.

---

## 1. Authentication

Tokens are **opaque random strings** (not JWT). Only SHA-256 hashes are stored.
- access: 43-char base64url, TTL 15 min
- refresh: 43-char base64url, TTL 30 days, **rotated on every refresh**; reuse of a
  rotated token revokes the whole token family (theft detection)

### POST /auth/register
```json
req  : { "email": "a@b.co", "password": "Str0ng!Passw0rd", "display_name": "umaiz" }
resp : 201 { "user": { "id": 1, "email": "a@b.co", "display_name": "umaiz",
                       "email_verified": false, "created_at": "..." },
             "access_token": "...", "refresh_token": "...", "expires_in": 900 }
```
Rules: email lowercased+trimmed+validated; password ≥10 chars with letter+digit
(configurable); `display_name` 3-20 chars `[A-Za-z0-9_]`; unique email → `EMAIL_TAKEN`
(409). Passwords: `password_hash(..., PASSWORD_ARGON2ID)` (fallback `PASSWORD_BCRYPT`).

### POST /auth/login
```json
req  : { "email": "a@b.co", "password": "..." }
resp : 200 { "access_token","refresh_token","expires_in","user" }
```
`INVALID_CREDENTIALS` (401) for unknown email **and** wrong password (identical
response + identical timing budget ≥ 100 ms) to prevent enumeration.

### POST /auth/refresh
```json
req  : { "refresh_token": "..." }        resp: { "access_token","refresh_token","expires_in" }
```
Rotation: old refresh token is marked used; reuse ⇒ `TOKEN_INVALID` (401) + revoke family.

### POST /auth/logout   **[auth]**
```json
req  : { "refresh_token": "..." }        resp: 200 { "ok": true }
```
Idempotent: already-revoked tokens return success.

### GET /auth/me  **[auth]**
```json
resp: { "user": { "id","email","display_name","email_verified","avatar_seed",
                  "ratings": { "bullet": {"rating":1200,"games":0}, ... },
                  "created_at" } }
```

### POST /auth/verify-email
```json
req  : { "token": "..." }   resp: 200 { "ok": true }
```
Tokens: 32 random bytes, single-use, SHA-256 stored, TTL 24 h, invalidated on
account deletion. Always `200 { ok:true }` shape with generic message on failure —
never reveals whether a token/email exists (`TOKEN_INVALID` for malformed,
but unknown tokens also return `TOKEN_INVALID`, not `NOT_FOUND`).

### POST /auth/resend-verification
Body `{ "email": "..." }` (logged out) or **[auth]** (no body). Always `200 { ok: true }`.
If mail transport is not configured ⇒ `503 MAIL_NOT_CONFIGURED` (dev-only payload may
include `dev_preview` when `APP_ENV=development`).

### POST /auth/forgot-password  → always 200 { ok: true } (enumeration-safe)
### POST /auth/reset-password   `{ "token", "password" }` → 200, revokes all sessions
### DELETE /account  **[auth]** `{ "password" }` → 200; hard-deletes user rows,
tokens, ratings, and orphans match records (retention documented in SECURITY.md)

---

## 2. Health

- `GET /health` → `{ status:"ok", time, version }` (no DB required)
- `GET /ready`  → 200 when DB reachable, else 503

---

## 3. Matchmaking & matches

Modes: `bullet | blitz | rapid | classical`. Time controls expressed as
`initial_time` (seconds) + `increment` (seconds), e.g. 300+3.

### POST /matchmaking/quick **[auth]**
```json
req  : { "mode": "blitz", "rated": true, "initial_time": 300, "increment": 3 }
resp : { "queue_id": "..." }
```
Pairs the first two compatible queued players (same mode+TC, ratings within
±400 expanding by 25/5 s, capped ±800). Opponents are **real accounts only** —
the server never fabricates a bot player.

### POST /matchmaking/cancel **[auth]** `{ "queue_id" }` → `{ "ok": true }`
### GET  /matchmaking/status **[auth]** → `{ "state": "idle|queued|matched", "match_id": ... }`
(polled every 1 s by the client; `matched` includes opponent summary + color)

### POST /matches **[auth]** (private game)
```json
req  : { "mode":"rapid","rated":false,"initial_time":600,"increment":5 }
resp : { "match": { "id","invite_code":"K7QF2N","expires_at", "color":"white" } }
```
Invite code: 6 chars from an unambiguous alphabet, TTL 10 min.

### POST /matches/join **[auth]** `{ "invite_code" }` → match summary (opponent, your color)

### GET /matches/{id} **[auth]** →
```json
{ "match": { "id","mode","rated","initial_time","increment",
             "players": { "white": {"id","display_name","rating","avatar_seed"},
                          "black": { ... } },
             "status": "waiting|signaling|live|finished|aborted",
             "result": null | { "winner":"white|black|draw","reason":"checkmate|timeout|resign|agreement|stalemate|..." },
             "started_at","finished_at" } }
```

### Signaling (WebRTC) — HTTPS long-poll
> A WebSocket server would require a persistent-process PHP dependency that is not
> available in this environment; long-polling over HTTPS is used instead and is
> documented as the upgrade path to `wss://`.

- `POST /matches/{id}/signal **[auth]** { "type":"offer|answer|candidate|bye", "payload":{...}, "to": <player_id> }`
  → `{ "seq": n }` (payload size ≤ 8 KB; only `offer/answer/candidate` allowed)
- `GET  /matches/{id}/signal?since=<seq> **[auth]**` → `{ "signals":[ {seq,type,from,payload,ts} ], "expires":bool }`
  long-polls up to 25 s, returns empty array on timeout.

Signaling messages are relayed verbatim but **rate-limited** (60/s) and size-capped.

### Events (gameplay) — server-validated
`POST /matches/{id}/events **[auth]**`
```json
req  : { "seq": 12, "type": "move", "uci": "e2e4", "san": "e4",
         "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1",
         "client_ts": 1730000000000 }
resp : { "ack": 12, "fen": "...", "white_ms": 298210, "black_ms": 297640,
         "server_ts": 1730000000000, "status": "live" }
```
Other `type` values: `draw_offer`, `draw_accept`, `draw_decline`, `resign`,
`sync` (resync request), `premove` (ignored).

Server rules:
1. Only the side-to-move's authenticated player may send `move` (else `FORBIDDEN`).
2. `seq` must be exactly `last_seq+1` (else `CONFLICT` + authoritative state payload).
3. **The move is replayed server-side** with the server's chess rules implementation
   from the stored start position; illegal ⇒ `ILLEGAL_MOVE` and the match state is
   left unchanged (and the match is flagged for review).
4. Server clock is authoritative: `white_ms/black_ms` computed from
   `started_at` + moves, never from client timestamps.
5. On game end the server derives `result` itself; a client-declared result is only
   a hint and is re-derived from the replayed history. `RESULT_REJECTED` if they disagree.
6. Rated matches update ratings only after server-side verification succeeds
   (`matches.verified = 1`), in a single transaction, once
   (`rating_updates.match_id` unique).

### GET /matches/{id}/history **[auth]** → `{ "moves":[{seq,uci,san,fen}], "result":..., "pgn":"..." }`

---

## 4. Games (history sync)

- `POST /games **[auth]** — idempotent upload of a finished local/online game
  ```json
  req : { "client_match_id":"uuid", "mode":"blitz","rated":false,"result":"win",
          "reason":"checkmate","color":"white","initial_time":300,"increment":3,
          "pgn":"...","moves":["e2e4",...],"started_at":"...","finished_at":"...",
          "opponent_name":"..." }
  resp: { "id": 55, "duplicate": false }
  ```
  (`client_match_id` UNIQUE per user ⇒ retries never duplicate rows)
- `GET /games?result=win&mode=blitz&page=1&per_page=25 **[auth]**` → paginated list
- `GET /games/{id} **[auth]**` → full record (owner only)
- `GET /games/{id}/pgn **[auth]**` → `text/plain` PGN

## 5. Statistics

- `GET /stats/summary?period=7d|30d|all **[auth]**`
```json
{ "rating": {...}, "highest": {...},
  "totals": { "played":0,"wins":0,"losses":0,"draws":0,"win_pct":0,"streak":0 },
  "modes": { "bullet": {...}, "blitz": {...}, "rapid": {...}, "classical": {...} },
  "history": [ { "ts":"...","rating":1200 } ] }
```
Empty for new accounts — **never fabricated**.

## 6. Leaderboard

- `GET /leaderboard?mode=blitz&page=1&per_page=50&q=name **[auth optional]**
```json
{ "page":1,"per_page":50,"total":0,"mode":"blitz",
  "entries":[ { "rank":1,"id":9,"display_name":"umaiz","rating":1287,
                "games":42,"avatar_seed":"..." } ],
  "me": { "rank": 17, "rating": 1204 } | null }
```
Deterministic order: `rating DESC, highest_rating DESC, wins DESC, id ASC`.
Rated games only. Query min length 2 chars, result ≤ 50 rows, cached 15 s.
A fresh install returns an empty leaderboard (valid state).

- `GET /leaderboard/me?mode=` → current user's rank only

## 7. Players

- `GET /players/{id}` → public profile (display name, ratings, game counts, country if set)

## 8. Puzzles / progress

- `GET /puzzles?difficulty=1|2|3&page=1` → server copy of the same verified dataset
  shipped in the APK (used to sync progress for signed-in users)
- `POST /puzzles/{id}/attempt **[auth]** `{ "solved": true, "moves": 3, "ms": 12000 }`
  → `{ "xp": ..., "progress": { "solved": n } }`

## 9. Watch

- `GET /watch/games` → `{ "games": [ ...live, server-published, verified matches... ] }`
  Empty array when none. The client shows an explained empty state; it must never
  render fake matches.

---

## 10. WebRTC gameplay protocol (over DataChannel, not HTTP)

Envelope (JSON, ≤ 1 KB per message, ≤ 64 KB for `sync`):
```json
{ "v": 1, "match": "<match_id>", "seq": 12, "type": "move", "from": "white", "data": { } }
```
Types: `hello` (exchange of `{player_id, color, last_seq, fen}` on channel open),
`move`, `ack`, `draw_offer|draw_accept|draw_decline`, `resign`, `game_over`,
`sync_request|sync_state`, `ping|pong` (heartbeat 5 s, dead after 20 s).

Validation on receipt: `v===1`, `match` matches, `from` equals the server-issued
color of the sender, `seq` monotonic (duplicates dropped), size cap, known type.
Clients **never** trust a peer-supplied result/rating/FEN for competitive effect —
the HTTP event API (§3) is authoritative and the server replays every move.

Protocol version mismatch ⇒ show "update required", do not play.
