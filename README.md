# Vietnamese Practice - Backend

PHP 8.2+ / [CodeIgniter 4](https://codeigniter.com) JSON API for the Next.js frontend in `../vietnamese-practice-master`. The contract is specified in [docs/API.md](docs/API.md).

## Architecture (MVC)

| Layer | Location | Role |
|---|---|---|
| Model | `app/Models` | Database access (`UserModel`, `RefreshTokenModel`, `WritingModel`, `WritingErrorModel`) |
| View | `app/Presenters` | Shapes rows into the JSON responses in `docs/API.md` (`WritingPresenter`) |
| Controller | `app/Controllers` | Thin HTTP layer: `AuthController`, `WritingController`, `HealthController` (base: `BaseApiController`) |
| Business logic | `app/Libraries` | `AuthService`, `WritingService`, `JwtService`, `RateLimiter`, analyzers in `Analysis/` |
| Cross-cutting | `app/Filters`, `app/Exceptions` | JWT auth, security headers / request ID, uniform error envelope |
| Config | `app/Config` | `Api.php` (app settings), `Routes.php`, `Filters.php`, `Cors.php`, `Validation.php` |

The analysis is behind `AnalyzerInterface`. `MockAnalyzer` (dictionary + heuristics, the server-side port of the frontend's old `mock-ai.ts`) is the default; plug in a real AI service by implementing the interface and selecting it in `Config\Services::analyzer()` via `api.analyzer`. Whatever an analyzer returns is validated by `ErrorSanitizer` before it is stored.

## Setup

Requirements: PHP >= 8.2 with `intl`, `mbstring`, `sqlite3` (or `mysqli`), `openssl`; Composer.

```bash
composer install
cp env .env                      # then set api.jwtSecret (see below)
php spark migrate
php spark db:seed DemoUserSeeder # dev only: user "demo" / "demo123"
php spark serve --port 8080
```

Generate the JWT secret (at least 32 bytes): `php -r "echo bin2hex(random_bytes(32));"` and set it as `api.jwtSecret` in `.env`.

Create real users with `php spark user:create <username>` (the password is prompted).

### Database

SQLite (`writable/database.sqlite`) is the default. For MySQL/MariaDB uncomment the `database.default.*` lines in `.env` and run `php spark migrate` again.

### Frontend

In `../vietnamese-practice-master` copy `.env.example` to `.env.local` (`NEXT_PUBLIC_API_URL=http://localhost:8080/api/v1`) and run `npm run dev`. The frontend origin must be listed in `api.corsAllowedOrigins` (default `http://localhost:3000`).

### Tests

```bash
composer test
```

Feature tests cover auth (login, rotation and reuse detection, logout, rate limiting, CORS) and writings (create, validation, idempotency, ownership, history pagination, delete); unit tests cover UTF-16 offsets, normalization, the analyzer and the output sanitizer. They use an in-memory SQLite database.

## Configuration (`.env`)

| Key | Default | Meaning |
|---|---|---|
| `CI_ENVIRONMENT` | `production` | Use `development` locally |
| `api.jwtSecret` | - | **Required**, >= 32 bytes |
| `api.corsAllowedOrigins` | `http://localhost:3000` | Comma separated frontend origins |
| `api.accessTokenTtl` / `api.refreshTokenTtl` | 900 / 2592000 | Seconds |
| `api.loginMaxAttempts` / `api.loginWindow` | 5 / 900 | Failed logins per username+IP per window |
| `api.analysesPerHour` | 20 | Analyses (create + retry) per user per hour |
| `app.forceGlobalSecureRequests` | `false` | Set `true` behind HTTPS (redirect + HSTS) |

## Security summary

Implemented as described in section 6 of `docs/API.md`: short-lived HS256 JWT (pinned algorithm, `iss`/`aud`/`exp` checked) bound to a revocable session; opaque refresh tokens stored as SHA-256 hashes with rotation and reuse detection; argon2id (bcrypt fallback) password hashing with a generic login error and dummy hashing for unknown users; login and analysis rate limiting; parameterized queries only with allow-listed sort/filter values; JSON-only responses with `nosniff`, a restrictive CSP, `X-Frame-Options`, `Cache-Control: no-store`; per-user ownership checks on every writing query; CORS limited to the configured origins; request body size limit.
