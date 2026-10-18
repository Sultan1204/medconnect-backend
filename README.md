# MedConnect Saudi — Backend

REST API (`/api/v1`) for a telemedicine marketplace connecting patients, licensed
doctors and pharmacies in Saudi Arabia. Serves the Vue.js web app and the Flutter
mobile apps.

> **Status:** feature-complete against the instruction phases 1–8 (mock integrations); Phase 9 hardening partly open — see
> [docs/implementation-checklist.md](docs/implementation-checklist.md).
> Technical implementation is **not** regulatory approval. Every government,
> payment, prescribing and pharmacy integration runs on a mock adapter until
> the approvals in [docs/compliance/requirements-register.md](docs/compliance/requirements-register.md)
> exist.

## Stack

| Component | Version |
|---|---|
| PHP | 8.3 |
| Laravel | 13.x (13.37.0 locked) |
| PostgreSQL | 17 |
| Redis | 7.4 |
| laravel/sanctum | 4.3 (bearer tokens) |
| spatie/laravel-permission | 8.3 |
| laravel/reverb | 1.12 (WebSockets) |
| laravel/horizon | 5.50 (queues) |
| dedoc/scramble | 0.13 (OpenAPI 3.1) |
| pragmarx/google2fa | 9.1 (TOTP MFA) |
| larastan/larastan | 3.13 (PHPStan level 6) |
| laravel/pint | 1.32 |
| phpunit/phpunit | 12.5 |

Exact versions are pinned in `composer.lock`.

## Quick start (Docker — recommended)

Requirements: Docker Desktop (or Docker Engine + Compose v2), PHP 8.3 + Composer
on the host *or* run Composer inside the container.

```bash
cd backend
php scripts/make-env.php .env.example .env    # creates .env with random local secrets
composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix   # flags only needed on Windows hosts
docker compose up -d --build
docker compose exec app php artisan migrate --seed
curl http://localhost:8000/api/v1/health
```

| Service | URL |
|---|---|
| API | http://localhost:8000/api/v1 |
| API docs (local only) | http://localhost:8000/docs/api |
| WebSockets (Reverb) | ws://localhost:8000/app/{REVERB_APP_KEY} (via nginx) or :8080 |
| Mailpit (captured email) | http://localhost:8025 |
| Horizon (queues, local only) | http://localhost:8000/horizon |
| S3 emulator (optional) | `docker compose --profile storage up -d` → http://localhost:9090 |

In local development SMS messages (including OTP codes) are written to
`storage/logs/laravel-*.log` (search for `[dev-sms]`). The `log` driver refuses
to run in production.

### Demo accounts (synthetic)

`php artisan db:seed` (non-production only) creates accounts with the password
`Password123`. Privileged accounts use the TOTP secret
`JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP` (add it to any authenticator app).

| Role | Phone | Email |
|---|---|---|
| Patient | +966500000001 | patient@medconnect.local |
| Doctor | +966500000002 | doctor@medconnect.local |
| Pharmacy staff | +966500000003 | pharmacy@medconnect.local |
| Admin | +966500000004 | admin@medconnect.local |
| Super admin | +966500000005 | superadmin@medconnect.local |

## Common commands

```bash
# inside the container: prefix with `docker compose exec app`
php artisan migrate                  # apply migrations
php artisan migrate:fresh --seed     # rebuild local DB with demo data
php artisan test                     # full test suite (PostgreSQL: medconnect_test)
php artisan test --filter=Login      # subset
vendor/bin/pint                      # format
vendor/bin/pint --test               # check formatting (CI)
vendor/bin/phpstan analyse --memory-limit=1G
php artisan scramble:export          # regenerate docs/api/openapi.json
composer audit                       # dependency vulnerability check
```

Tests run against a real PostgreSQL database (`medconnect_test`, created by
`docker/postgres/init`). Running them from the host only requires the
`postgres` container: `docker compose up -d postgres`.

## Layout

```
app/
  Modules/<Module>/      one folder per bounded context (see docs/architecture.md)
    Actions/             single-purpose use cases (one public handle())
    Services/            reusable domain services
    Contracts/           integration interfaces (SMS, video, payments, prescriptions...)
    Http/                Controllers, Requests (validation), Resources (JSON), Middleware
    Models/ Enums/ Events/ Jobs/ Policies/
    routes.php           module routes, registered via config/modules.php
  Support/               cross-cutting: error envelope, middleware, health check, phone numbers
config/medconnect.php    platform settings and integration drivers
database/                migrations, factories (synthetic data), seeders
docker/                  PHP-FPM image, nginx, PostgreSQL init
docs/                    architecture, API, security, compliance
tests/                   Unit / Feature / Integration (PHPUnit)
```

## Documentation

- [Architecture & module boundaries](docs/architecture.md)
- [Implementation checklist](docs/implementation-checklist.md)
- [API conventions & error codes](docs/api/error-codes.md) — OpenAPI: `docs/api/openapi.json`
- [Security](docs/security.md)
- [Compliance requirements register](docs/compliance/requirements-register.md)
