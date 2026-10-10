# Backend API Specification

API set required by the frontend (authentication, writing submission and grading, history). Language-agnostic.

## 1. Conventions

- **Base path:** `/api/v1`. Format is JSON, UTF-8, ISO-8601 UTC timestamps.
- **Auth:** `Authorization: Bearer <accessToken>` on every endpoint except login and refresh. An HttpOnly cookie is an acceptable alternative; if used, the frontend must send credentials with each request.
- **Error format** (all non-2xx responses):
  ```json
  { "error": { "code": "VALIDATION_FAILED", "message": "Human-readable (Vietnamese)", "details": [{ "field": "text", "message": "..." }] } }
  ```
- **Status codes:**

  | Code | Meaning |
  |---|---|
  | 400 | malformed request |
  | 401 | missing or invalid token |
  | 403 | resource belongs to another user |
  | 404 | not found |
  | 409 | conflict |
  | 422 | validation failed |
  | 429 | rate limited |
  | 500, 502, 503, 504 | server or upstream failure |

- **Ownership:** every history and analysis resource is scoped to the authenticated user. Return 404 (not 403) for other users' resources if you want to avoid leaking existence.
- **Enums** (must match the frontend):
  - `tone`: `professional` | `casual`
  - `audience`: `adult` | `child` | `colleague` | `close-friend`
  - `category`: `grammar` | `vocab` | `coherence`
  - `status` (error decision): `pending` | `accepted` | `rejected`

## 2. Authentication

### POST `/auth/login`
Replaces the mock `login()`.
- **Request:** `{ "username": string, "password": string }`
- **Validation:** both required. Username is trimmed. Password is never trimmed or logged.
- **Success 200:**
  ```json
  { "accessToken": "...", "refreshToken": "...", "expiresIn": 900, "user": { "id": "...", "username": "demo" } }
  ```
- **Failure:**
  - 401 `INVALID_CREDENTIALS` with one generic message for both wrong username and wrong password.
  - 429 `TOO_MANY_ATTEMPTS` after N failed attempts per username and IP (e.g. 5 in 15 minutes), with a `Retry-After` header.
- **Security:** store passwords hashed with a slow salted algorithm (argon2, bcrypt or scrypt), use constant-time comparison, require HTTPS.

### POST `/auth/refresh`
- **Request:** `{ "refreshToken": string }`
- **Success 200:** new access token, plus a new refresh token if rotating (recommended).
- **Failure:** 401 if the token is expired, revoked or reused. Reuse of a rotated token should revoke the whole token family.

### POST `/auth/logout`
- Auth required. Revokes the current refresh token or session. Returns 204 and is idempotent.

### GET `/auth/me`
- **Success 200:** `{ "id", "username" }`
- Called by the frontend on page load to restore the session (replaces the localStorage check). A 401 means "redirect to `/login`".

Registration and password reset are not in the UI yet. Add them only if needed.

## 3. Writing and analysis

Flow: submit text with tone and audience, show a loading state, then show errors. Each error has a category, a character range, an original string, a suggestion and an explanation. The user accepts or rejects each one. The frontend currently simulates a 1-2 s delay and a ~12% failure rate, so the API must handle slow and failing analysis explicitly.

### Option A (simple): synchronous analysis

**POST `/writings`** creates a writing, runs the analysis, and returns the result.
- **Request:**
  ```json
  { "text": "string", "tone": "casual", "audience": "child" }
  ```
- **Validation:**
  - `text` required, at least 20 words (whitespace-separated, matching the frontend `MIN_WORD_COUNT`).
  - Maximum length, e.g. 10,000 characters; 422 above it.
  - `tone` and `audience` required and valid enum values.
- **Success 201:** the writing resource (3.1) with `status: "completed"`.
- **Failure:**
  - 422 on validation.
  - 502/504 `ANALYSIS_FAILED` if the AI service fails or times out. Either do not save a history entry, or save one with `status: "failed"` so the user can retry.
- **Timeout:** server timeout of 30 s or more.

### Option B (recommended if the AI is slow): asynchronous analysis

1. **POST `/writings`** returns 202 with `{ "id", "status": "processing" }`.
2. **GET `/writings/{id}`** is polled by the frontend every 1-2 s until `status` is `completed` or `failed`. SSE or WebSocket can be added later.

Use Option B if the AI call can exceed ~10 s.

### 3.1 Writing resource

```json
{
  "id": "uuid",
  "text": "original text (immutable)",
  "tone": "casual",
  "audience": "child",
  "wordCount": 42,
  "status": "processing | completed | failed",
  "failureReason": null,
  "errors": [
    {
      "id": "uuid",
      "category": "grammar",
      "status": "pending",
      "startIndex": 24,
      "endIndex": 32,
      "original": "thứt dậy",
      "suggestion": "thức dậy",
      "explanation": "..."
    }
  ],
  "summary": { "total": 3, "pending": 3, "accepted": 0, "rejected": 0, "byCategory": { "grammar": 1, "vocab": 1, "coherence": 1 } },
  "createdAt": "ISO",
  "updatedAt": "ISO"
}
```

Rules the AI/backend result must satisfy:
- `startIndex` and `endIndex` are character offsets into the exact stored `text`, with `endIndex` exclusive. Define them as UTF-16 code units, since the frontend is JavaScript. Vietnamese uses precomposed and combining diacritics, so different Unicode normalizations give different offsets: store the text normalized as NFC and return offsets against that same text.
- Errors must not overlap. Return them sorted by `startIndex`.
- `original` must equal `text.slice(startIndex, endIndex)` (trimmed in the mock; pick one rule and document it).
- `explanation` is in Vietnamese.
- Zero errors is valid and returns `errors: []`.
- Errors are persisted so reopening from history does not call the AI again.

### POST `/writings/{id}/retry`
- Re-runs analysis for a writing in `failed` state (the frontend has a retry button).
- Returns 202 or 200 with the same resource shape. Returns 409 if the writing is not in `failed` state.

### PATCH `/writings/{id}/errors/{errorId}`
- Records the user's decision when they accept or reject a suggestion.
- **Request:** `{ "status": "accepted" | "rejected" | "pending" }`. `pending` allows undo.
- **Success 200:** the updated error plus the updated `summary`.
- **Failure:** 404 if the error does not belong to the writing, 422 on a bad status value.
- Idempotent. The frontend can call it optimistically and roll back on failure.
- Optional: `PATCH /writings/{id}/errors` with a batch `[{ id, status }]`.

## 4. History

### GET `/writings`
Lists the current user's writings, newest first.
- **Query:** `limit` (default 20, max 100), `cursor` (or `page`/`pageSize`), optional `q` (text search), `tone`, `audience`, `from`/`to` dates, `sort=createdAt:desc` (default).
- **Success 200:**
  ```json
  {
    "items": [{ "id", "textPreview": "first ~200 chars", "wordCount", "tone", "audience", "status", "errorSummary": { "total", "pending" }, "createdAt" }],
    "nextCursor": "opaque | null",
    "total": 57
  }
  ```
- List items omit the full text and error list to keep payloads small. The history page shows a 3-line preview, date, word count, tone and audience, so those must be included.
- The current frontend loads everything at once; add pagination or infinite scroll on the page when integrating.

### GET `/writings/{id}`
- Returns the full resource from 3.1, including saved errors and each error's accept/reject status.
- Used by "reopen from history". Today reopening re-runs the mock analysis, so decisions are lost; with this endpoint they are kept.
- **Failure:** 404 if missing or owned by someone else.

### DELETE `/writings/{id}`
- Deletes one writing and its errors. Returns 204 and is idempotent (a repeat call may return 404 or 204; pick one).

### DELETE `/writings`
- Deletes all of the user's writings (the "Xóa tất cả" button).
- Require a confirmation signal, e.g. `?confirm=true`, to prevent accidents. Returns 204.

## 5. Non-functional requirements

- **Rate limiting:** writing creation per user (e.g. 20/hour); login attempts per IP and username. Return 429 with `Retry-After`.
- **Idempotency:** accept an optional `Idempotency-Key` header on `POST /writings` so double-clicks or retries do not create duplicates or repeat AI calls.
- **CORS:** allow only the frontend origin, with credentials if cookies are used.
- **Input safety:** treat `text` as untrusted. Escape it on output and pass it to the AI as data. Defend against prompt injection (instructions embedded in the essay) by keeping the instruction and user text clearly separated and validating the AI output against the schema above.
- **AI output validation:** after the AI returns, drop or repair any error whose offsets are out of range, overlap another, or whose `original` does not match the text.
- **Privacy and retention:** writings are personal data. Define a retention policy and make deletion real (hard delete or scheduled purge).
- **Observability:** request ID header on every response, structured logs without passwords or full essay text, metrics for analysis latency and failure rate.
- **Health:** `GET /health` (unauthenticated) for liveness and readiness.

## 6. Security

Covers authentication and authorization (JWT, sessions), password hashing, and protection against common vulnerabilities (SQL injection, XSS).

### 6.1 Authentication and authorization
- **JWT access tokens:**
  - Short-lived (e.g. 15 minutes, matching `expiresIn`), signed with a strong secret or asymmetric key (HS256 with a 256-bit+ secret, or RS256/ES256). Pin the allowed algorithm and reject `none`.
  - Validate `exp`, `iat`, `iss` and `aud` on every request. Keep claims minimal (`sub`, `jti`, `exp`); no sensitive data in the payload.
  - Load secrets and keys from environment or a secret manager, never from source control. Support key rotation.
- **Refresh tokens / sessions:**
  - Long-lived (e.g. 7-30 days), opaque and random (at least 128 bits). Store only a hash server-side, with user, expiry, device and a family ID.
  - Rotate on every refresh. Reuse of a rotated token revokes the whole family (see `/auth/refresh`). `/auth/logout` revokes it.
  - If using cookie-based sessions or tokens: set `HttpOnly`, `Secure`, `SameSite=Lax` (or `Strict`), a narrow `Path`, and add CSRF protection (CSRF token or `Origin` check) on state-changing requests. If tokens are sent in the `Authorization` header, store them in memory where possible and avoid `localStorage` for refresh tokens.
- **Authorization:**
  - Deny by default. Every endpoint except `/auth/login`, `/auth/refresh` and `/health` requires a valid token.
  - Enforce ownership on the server for every `writings` resource (filter by the authenticated user ID in the query itself, never trust a user ID from the client). Return 404 for other users' resources (see Conventions).
  - Never expose internal IDs, stack traces or token contents in errors.

### 6.2 Password handling
- Hash with argon2id (preferred), bcrypt (cost >= 12) or scrypt, using a unique random salt per password. Optionally add a server-side pepper kept outside the database.
- Compare in constant time. Rehash transparently on login when parameters are outdated.
- Enforce a password policy on any future registration/reset flow (minimum 8-12 characters, check against breached-password lists, no composition quirks, max length e.g. 128 to avoid DoS).
- Never log, echo, trim or return passwords or hashes. Return one generic message for wrong username or password and keep response timing similar (hash a dummy password for unknown users).
- Throttle and lock out brute force (see `/auth/login`: 429 `TOO_MANY_ATTEMPTS`).

### 6.3 SQL injection
- Use parameterized queries or an ORM/query builder for all database access. Never build SQL by concatenating or interpolating user input (including `sort`, `order`, filter and pagination parameters).
- Allow-list column names for sorting and filtering, and cast/validate numeric and enum parameters (`tone`, `audience`, `category`, `status`).
- Run the application with a least-privilege database account (no DDL, no superuser), and do not return raw database errors to clients.

### 6.4 XSS
- Treat `text`, usernames and all AI output as untrusted. Validate type, length and encoding on input, but rely on output encoding for protection.
- The API returns `application/json` only, with `Content-Type: application/json; charset=utf-8` and `X-Content-Type-Options: nosniff`. Do not return user content as HTML.
- The frontend must render essay text, error messages and suggestions as text (framework auto-escaping). Avoid `innerHTML` / `dangerouslySetInnerHTML`; if HTML is ever required, sanitize with an allow-list sanitizer.
- Send a restrictive `Content-Security-Policy` (e.g. `default-src 'self'`; no inline scripts) from the frontend host.

### 6.5 Transport and other hardening
- HTTPS only, with HSTS (`Strict-Transport-Security`). Redirect or reject plain HTTP.
- Security headers: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` (or CSP `frame-ancestors 'none'`), `Referrer-Policy: no-referrer`, and `Cache-Control: no-store` on auth and user-data responses.
- CORS: explicit origin allow-list, never `*` together with credentials (see section 5).
- Limit request body size (e.g. 1 MB, plus a max `text` length) and set timeouts.
- Keep dependencies updated and scan them for known vulnerabilities in CI.
- Log security events (failed logins, token reuse, authorization failures) with the request ID, without secrets or full essay text.

## 7. Implementation decisions (CodeIgniter backend)

Choices made where this specification leaves room:

- **Analysis:** option A (synchronous). If the analyzer fails, the writing is saved with `status: "failed"` and the response is 502 (504 on timeout) `ANALYSIS_FAILED` with the id in `error.writingId`, so the client can call `POST /writings/{id}/retry`.
- **Offsets:** `startIndex`/`endIndex` are UTF-16 code units into the stored text (trimmed, NFC). `original` is exactly `text.slice(startIndex, endIndex)`, with no surrounding whitespace. Errors that violate this, overlap, or have an unknown category are dropped before saving.
- **Idempotency:** a repeated `POST /writings` with the same `Idempotency-Key` returns the existing writing with 200 (the first call returns 201). Keys are scoped per user.
- **Deletes:** `DELETE /writings/{id}` always returns 204, including for missing or other users' writings. `DELETE /writings` without `?confirm=true` returns 400 `CONFIRMATION_REQUIRED`.
- **Ownership:** other users' writings return 404.
- **Pagination:** `GET /writings` uses an opaque `cursor` (ordered by `createdAt`, then `id`); `sort` accepts `createdAt:desc` (default) or `createdAt:asc`. `q` is a substring match on the text.
- **PATCH error response:** `{ "error": { ... }, "summary": { ... } }` (the updated error and the writing's summary).
- **Batch save ("Lưu"):** `PATCH /writings/{id}/errors` with `{ "errors": [{ "id", "status" }] }` (1-500 items, wrapped in an object rather than a bare array). It is all or nothing: an id that is not an error of the writing returns 404 and changes nothing. Returns 200 `{ "errors": [...], "summary": {...} }` with every error of the writing. The frontend keeps accept/reject choices locally and sends only the changed ones when the user clicks Lưu.
- **Sessions:** the access token carries a session id (`sid`) that is checked against the refresh-token family on every request, so logout and reuse detection take effect immediately. Tokens are sent in the `Authorization` header (no cookies), so CORS credentials are disabled and CSRF does not apply. The frontend keeps the access token in memory and the refresh token in `sessionStorage`.
- **Health:** `GET /health` (outside `/api/v1`) returns `{ "status": "ok" }`, or 503 when the database is unreachable.
