# Security

This document describes the controls **implemented in code** and the review
status. It is not a certification or an audit report. Items marked *open* need
action outside this repository.

## Authentication and sessions
- Sanctum bearer tokens with expiry, per-device listing and revocation. Password reset and password change revoke sessions; suspension revokes all tokens at once.
- Passwords hashed (bcrypt via Laravel); per-account lockout and rate limits on every credential endpoint; timing-equalised login.
- OTP codes stored as keyed HMAC, single active code, expiry, attempt limits, resend cooldown.
- TOTP MFA is mandatory for doctor, pharmacy staff and admin roles; replay protection; recovery codes. A password alone never yields a full token for those roles (`token.full`).
- **Step-up MFA** (`X-MFA-Code`) on high-risk actions: doctor/pharmacy verification, refunds, payout preparation, user suspension/reactivation, data-request handling, settings changes, administrator permission changes.

## Authorisation
- Roles: patient, doctor, pharmacy_staff, admin, super_admin. Administrators hold *permissions* (`users.view`, `payments.refund`, …); super-admin-only permissions (`admins.manage`, `integrations.manage`, `security.manage`) cannot be granted to admins.
- `data_requests.manage` (PDPL requests) is **not** in the default admin set; a super administrator grants it explicitly.
- Access to *records* is relationship-based in policies (care relationship, guardian link, branch membership). No role, including admin, reads consultation content or medical records.
- Admins cannot act on themselves, cannot modify super administrators, and only a super administrator can act on another administrator.
- Missing and inaccessible resources return the same response where existence would otherwise leak.

## Data protection
- Clinical and personal free text is encrypted at rest with Laravel `encrypted` casts (intake, messages, notes, records, complaints, data-request details). Key rotation through `APP_PREVIOUS_KEYS`.
- Audit entries, notification parameters, integration events and logs hold identifiers and field names only. Message bodies and clinical data are never logged.
- Contact details are masked in administrator user listings.
- Uploads: MIME allow-list, size limit, scan interface, private storage, 5-minute signed links.
- Money is stored as integer minor units; ledger and audit log are append-only (database triggers).
- Synthetic data only in seeders/factories; `.env` is not committed.

## Integrity and abuse controls
- Database constraints (CHECK, exclusion, triggers) back the main invariants: no double booking, one capture per appointment, immutable booking snapshots, immutable clinical note versions.
- Idempotency keys on booking and checkout; webhooks signed, timestamped, de-duplicated.
- Rate limiting on auth, booking, complaints and reviews.
- Security headers on every response; forced JSON; request IDs in errors and audit entries.
- Operator-editable settings are an allow-list with bounds; drivers, secrets and `PAYOUTS_ENABLED` are never editable through the API.

## Review status
| Area | Status |
|---|---|
| Automated tests (448 tests on PostgreSQL) incl. permission denials and audit entries | Done |
| Static analysis (PHPStan level 6) | Configured in CI; **not run in the build environment used for Phases 7–9**: run before release |
| `composer audit` | In CI; not verifiable offline here |
| Independent penetration test | *Open* |
| Threat-model workshop with clinical/legal owners | *Open* |
| Real malware scanner, real SMS/payment/video/prescription providers | *Open* — mock adapters only |
| Secrets management, TLS termination, WAF, log shipping in staging/production | *Open* — see [deployment.md](deployment.md) |

## Known limitations
- Operator settings changes are read at process start; workers and the scheduler must be restarted after a change.
- Reports query live tables; for large volumes move them to a read replica.
- Audit log retention/archival policy is not defined (needs legal input).

## Reporting a vulnerability
Report privately to the project maintainers; do not open a public issue.
