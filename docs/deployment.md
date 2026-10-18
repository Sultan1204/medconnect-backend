# Deployment, backup and restore

Staging/production is **not provisioned** by this repository. This guide lists
what a deployment needs. Values below are examples; use your own secrets store.

## Services
PHP-FPM app, nginx (TLS terminated here or at the load balancer), PostgreSQL 17,
Redis 7.4, Reverb, Horizon (queue workers), scheduler (`schedule:work` or cron
`schedule:run` every minute), private S3-compatible object storage.

`compose.yaml` is for local development only.

## Release procedure
1. CI green: Pint, PHPStan, `php artisan test`, `composer audit`.
2. Back up the database (below).
3. `php artisan down --retry=60` (optional for breaking migrations).
4. Deploy code; `composer install --no-dev --optimize-autoloader`.
5. `php artisan migrate --force`.
6. `php artisan config:cache route:cache event:cache`.
7. **Restart** workers, Horizon, Reverb and the scheduler (`php artisan horizon:terminate`; restart the scheduler). Required so config and operator settings overrides are re-read.
8. `php artisan up`; check `GET /api/v1/health`.

Rollback: restore the previous release; migrations are reversible (`migrate:rollback --step=N`) but prefer forward fixes after data was written.

## Production configuration rules
- `APP_ENV=production`, `APP_DEBUG=false`, strong unique `APP_KEY` (keep old keys in `APP_PREVIOUS_KEYS` while rotating).
- Drivers: `log` SMS/push drivers refuse to run in production; real providers must be added behind the existing interfaces first. Do not set mock drivers in production with real users.
- `PAYOUTS_ENABLED=false` until reviewed.
- Database user with least privilege; separate migration user.
- Secrets from a secrets manager, never committed.

## Backup
- PostgreSQL: nightly `pg_dump -Fc` plus continuous WAL archiving (point-in-time recovery). Encrypt backups and store them in a different failure domain.
- Object storage: versioning enabled; lifecycle rules per retention policy (undefined, see compliance register #9).
- Keep `APP_KEY`/`APP_PREVIOUS_KEYS` separately: encrypted columns cannot be read without them.

## Restore drill (perform before go-live and quarterly)
1. Provision an empty PostgreSQL.
2. `pg_restore --clean --if-exists -d medconnect backup.dump`.
3. Point a staging app at it with the production `APP_KEY`.
4. `php artisan migrate --pretend` shows nothing pending; `GET /api/v1/health` is OK.
5. Verify: an encrypted field decrypts, `audit_logs` count matches, ledger balances reconcile (`settlement_entries` sums), a login works.
6. Record the elapsed time (RTO) and data loss window (RPO).

**Status:** the drill has **not** been performed — no staging environment exists yet.

## Monitoring (to set up)
Alert on: failed jobs (Horizon), queue latency, 5xx rate, webhook failures, `review_required` payments, scheduler heartbeat, disk, database connections.
