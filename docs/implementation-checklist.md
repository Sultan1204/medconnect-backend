# Implementation checklist

Legend: ✅ done & tested · 🟡 partial · ⬜ pending · ⛔ blocked (needs external approval/contract)

_Last updated: 2026-10-19_

## Phase 1 — Foundation
- ✅ Laravel 13 project, PHP 8.3, modular layout (`app/Modules`, `app/Support`)
- ✅ Docker Compose: app (PHP-FPM), nginx, PostgreSQL 17, Redis 7.4, Reverb, Horizon, scheduler, Mailpit, optional S3 emulator
- ✅ PostgreSQL + Redis configuration, test database bootstrap
- ✅ Environment template + local secret generator (`scripts/make-env.php`)
- ✅ Health check `GET /api/v1/health` (database + cache)
- ✅ Error envelope with stable codes, request IDs, security headers, forced JSON
- ✅ Pint, Larastan (level 6), PHPUnit on PostgreSQL, GitHub Actions CI
- ✅ OpenAPI 3.1 generation (Scramble) → `docs/api/openapi.json`

## Phase 2 — Identity
- ✅ UUID users, soft deletion, status (active/suspended/deactivated)
- ✅ Roles: patient, doctor, pharmacy_staff, admin, super_admin; least-privilege permission set
- ✅ Registration by phone (enumeration-safe), SMS OTP via `SmsProvider` abstraction
- ✅ OTP hashing (HMAC, keyed), expiry, attempt limits, resend cooldown, single active code
- ✅ Email verification (code), email change re-verification
- ✅ Password login (phone/email), timing-equalised, per-account lockout, rate limits
- ✅ Password reset (SMS/email), enumeration-safe, revokes all sessions
- ✅ Password change (revokes other sessions)
- ✅ TOTP MFA — mandatory for doctor/pharmacy/admin roles, optional for patients; replay protection; recovery codes
- ✅ Token issuance, expiry, revocation; device/session list and remote sign-out
- ✅ New-device sign-in & lockout detection (`SuspiciousActivityDetected` event)
- ✅ Account self-deactivation
- ✅ Append-only audit log (DB trigger) with actor, subject, purpose, request id
- ✅ Suspicious-activity *notification* to the user — event raised, delivered via Notifications (Phase 8)

## Phase 3 — Doctors and patients
- ✅ Patient profiles (identity separate from clinical data), encrypted emergency contact
- ✅ Dependants + guardian links (admin-verified by default), configurable age of majority
- ✅ Relationship-based `PatientPolicy` (`view`, `update`, `actFor`)
- ✅ Immutable, versioned consent records; booking-required consent check
- ✅ PDPL data-subject requests (submission; admin handling API in Phase 8)
- ✅ Doctor profiles, bilingual bios, languages, fees (halalas), unique licence per authority
- ✅ Credential uploads: server-side MIME check, malware-scan interface, private storage, 5-minute signed links
- ✅ Verification state machine with immutable decision history; licence change → re-review; scheduled expiry
- ✅ Admin verification APIs with step-up MFA (`X-MFA-Code`); admins cannot verify themselves
- ✅ Specialties (AR/EN, cached), admin management
- ✅ Public doctor search: specialty, name (AR/EN, trigram-indexed), language, type, gender, price, sort, pagination
- ⛔ Automated SCFHS verification — `ProfessionalVerifier` interface with manual driver only
- ⛔ Production malware scanning — `FileScanner` mock only (ClamAV/cloud adapter required before go-live)
- ✅ `available_on` search filter (rule/extra-window pre-filter; exact slots via availability endpoint)

## Phase 4 — Scheduling
- ✅ Weekly rules (local time, breaks, validity range), date-specific extra windows and time off
- ✅ Settings: minimum notice, booking horizon, buffer, daily cap, manual/auto confirmation
- ✅ Slot generation in Asia/Riyadh, returned as UTC instants
- ✅ Transactional booking: doctor row lock + PostgreSQL exclusion constraints (doctor *and* patient overlap)
- ✅ Immutable booking snapshot (price, currency, time, policy) enforced by DB trigger
- ✅ Idempotency-Key support, per-patient upcoming-booking limit, booking rate limit
- ✅ Free → confirmed/pending; paid → awaiting_payment with 15-min hold and scheduled expiry
- ✅ Cancellation with snapshotted refund policy; doctor accept/decline; no-show after grace period
- ✅ Guardian booking for verified dependants; consent check before booking
- ✅ Concurrency tests: two DB sessions (lock wait → exclusion violation) and 4 parallel PHP processes → exactly one booking
- ✅ Architecture tests: every model stores UTC and is in the morph map; every module route file registered

## Phase 5 — Consultations
- ✅ Consultation per confirmed appointment; cancelled with it
- ✅ Lifecycle scheduled → active ⇄ interrupted → completed; start window; doctor eligibility re-checked; scheduled time-limit enforcement
- ✅ Participant-only policy (no role — including admin — grants access)
- ✅ Encrypted patient intake; configurable red-flag screening returns emergency guidance immediately; doctor escalation; public `GET /emergency-info`
- ✅ Messaging: encrypted bodies, client-id de-duplication, sequence cursors (`before`/`after` for reconnect), delivery/read receipts, follow-up window
- ✅ Attachments: MIME allow-list, size limit, scan, private storage, 5-minute links
- ✅ Reverb private + presence channels with server-side authorisation; broadcasts carry IDs/metadata only
- ✅ Video: `VideoProvider` interface + mock; per-participant 10-minute credentials capped at the hard end; telemetry events; rooms close when the consultation stops being active; no recording
- ✅ Medical records: allergies, medications, history (encrypted); patient-reported vs clinician; retraction via `entered_in_error`
- ✅ Doctor access only via a consultation inside the care window; purpose recorded in audit log
- ✅ Clinical notes: private drafts, finalise (assessment + plan required), immutable versions (DB trigger), attributed amendments with reason
- ⛔ Real video provider — requires security/privacy/data-residency assessment
- 🟡 Emergency numbers & screening questions — configurable; require clinical governance sign-off

## Phase 6 — Payments
- ✅ Free consultations (price 0 → no payment, no ledger entries)
- ✅ `PaymentGateway` interface + mock with HMAC-signed, timestamped webhooks (replay window)
- ✅ Hosted checkout with mandatory `Idempotency-Key`; no card data handled
- ✅ Webhook is the only source of truth; per-event de-duplication; amount/currency mismatch → `review_required`
- ✅ Late payment (hold expired / cancelled) → automatic full refund
- ✅ Refunds: cancellation policy %, admin partial refunds (permission + step-up MFA), pending refunds reserve balance, webhook confirmation
- ✅ Commission snapshotted per payment (bps); integer halala arithmetic with exact splits
- ✅ Append-only ledger (DB trigger): earning on completion, proportional reversals, payouts
- ✅ Doctor earnings endpoint; admin balances; payout preparation with balance reservation
- ✅ Integration event log (latency, status, references - no payloads)
- ✅ Dev-only mock checkout page that drives the real webhook path
- ⛔ Real payment provider adapter — requires contract + SAMA-compliant provider
- ⛔ Payout execution — disabled (`PAYOUTS_ENABLED=false`) until provider capabilities and regulatory review are confirmed

## Phase 7 — Prescriptions & pharmacies
- ✅ Prescription drafts, state machine (events, immutable history), safety-check interface (`ClinicalSafetyChecker`), scheduled expiry
- ✅ Pharmacy onboarding with verification, branches, staff, dispensing workflow and request history
- ✅ Prescription/dispense status changes notify the patient (Phase 8 notifications)
- ⛔ Real e-prescription issuance (e.g. Wasfaty), real clinical safety source and pharmacy system integration — mock adapters only

## Phase 8 — Administration
- ✅ Notifications: in-app feed, SMS/email/push channels with per-user preferences (security category cannot be disabled), retries and outcome-only delivery records, push device registry, appointment reminders (24h/1h), event-driven producers (appointments, payments, prescriptions, dispensing, complaints, data requests)
- ✅ Reviews: one per completed consultation, review window, moderation queue (contact details always held), reports, doctor rating recalculated under lock
- ✅ Complaints: intake with relationship check on the subject, handling queue, assignment, priority, internal notes never shown to the complainant, closing requires a resolution, complainant notified
- ✅ User administration: search (masked contact details), suspend/reactivate with step-up MFA, session revocation, guard rails (not self, admins only by super admin, super admins untouchable)
- ✅ PDPL data requests: admin handling API under separate `data_requests.manage` permission, step-up MFA, audited; the system never deletes data automatically
- ✅ Audit log review (filters, cursor pagination; reviewing is itself audited)
- ✅ Operational reports: overview, appointments per day, financial (captured, refunded, net, commission, payouts); no personal data
- ✅ Operator settings: allow-listed, bounded, audited, system-tier keys super-admin only
- ✅ Administrator permission management (super admin, step-up MFA, audited)
- 🟡 Real SMS gateway, real push provider (FCM/APNs) and email provider — interfaces + log/fake drivers only (⛔ needs contracts/sender-ID registration)
- 🟡 Settings changes need a worker/scheduler restart to take effect (documented)

## Phase 9 — Hardening
- ✅ Security documentation: [security.md](security.md)
- ✅ Compliance register: [compliance/requirements-register.md](compliance/requirements-register.md)
- ✅ Deployment, backup and restore guide: [deployment.md](deployment.md)
- ✅ OpenAPI regenerated (`docs/api/openapi.json`)
- ✅ Full automated suite green on PostgreSQL (see README for the latest count)
- 🟡 Static analysis: PHPStan/Larastan is configured and runs in CI, but was **not executed** while building Phases 7–9 (not installable in the build sandbox) — run `vendor/bin/phpstan analyse` and fix findings before release
- ⬜ Restore drill — documented, **not performed** (no staging environment)
- ⬜ Performance review — no load testing done; indexes were designed with the main queries in mind but are unmeasured
- ⬜ Independent penetration test, threat-model review with legal/clinical owners
- ⬜ Staging deployment — guide only, nothing provisioned
- ⛔ Laravel Boost (`laravel/boost`) — could not be installed (package registry unreachable from the build sandbox); `CLAUDE.md` still holds the Boost bootstrap text
