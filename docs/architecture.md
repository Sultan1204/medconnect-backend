# Architecture

MedConnect Saudi is a **modular monolith**: one Laravel application, one
PostgreSQL database, with code split into bounded-context modules under
`app/Modules`. Modules communicate through Eloquent relationships for reads,
and through **domain events** where a write in one module must trigger work in
another (e.g. `Identity\UserRegistered` → Patients/Doctors create profiles).

```
            Vue SPA / Flutter apps
                    │  HTTPS, Bearer token          WebSocket (private channels)
                    ▼                                        │
   ┌──────────── nginx ─────────────┐                        │
   │  /api/v1/*  → PHP-FPM (app)    │   /app/* → Reverb ◄────┘
   └────────────────────────────────┘
        │            │            │
        ▼            ▼            ▼
   PostgreSQL     Redis        Private object storage (S3-compatible)
   (all data)   (cache, queues,     (attachments, credentials documents)
                 sessions)
                    │
            Horizon workers (notifications, OTP, webhooks, scans)
            Scheduler (reminders, expiry, pruning)
```

## Request pipeline

`AssignRequestId` → `ForceJsonResponse` → `auth:sanctum` → `account.active`
→ `token.full` (blocks MFA-pending tokens) → throttle → controller
→ Form Request (validation) → Policy (authorisation) → Action/Service
→ API Resource → `SecurityHeaders`.

Every error is rendered by `App\Support\Exceptions\ApiExceptionRenderer` into
`{"error": {"code", "message", "details"?, "request_id"}}`.

## Modules and table ownership

Each table is owned (written) by exactly one module. Other modules may read
through relationships but change data only via the owner's actions/events.

| Module | Responsibility | Owns tables |
|---|---|---|
| **Identity** | accounts, OTP, passwords, MFA, tokens/devices, roles | `users`, `otp_codes`, `personal_access_tokens`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` |
| **Patients** | patient profile, guardians, consent | `patients`, `patient_guardians`, `patient_consents` |
| **Doctors** | doctor profile, credentials, verification workflow | `doctors`, `doctor_credentials`, `doctor_verifications`, `doctor_specialties` |
| **Specialties** | bilingual specialty catalogue | `specialties` |
| **Scheduling** | weekly availability rules, date-specific slots/time-off, appointments | `availability_rules`, `availability_slots`, `appointments` |
| **Consultations** | consultation lifecycle, intake, emergency escalation | `consultations` |
| **Messaging** | consultation chat + attachments | `messages`, `message_attachments` |
| **Video** | provider-independent video sessions | `video_sessions` |
| **MedicalRecords** | clinical notes (versioned), allergies, medications, history | `medical_records`, `clinical_notes`, `clinical_note_versions`, `patient_allergies`, `patient_medications` |
| **Prescriptions** | prescription drafts → external approval workflow | `prescriptions`, `prescription_items`, `prescription_events` |
| **Pharmacies** | pharmacies, branches, staff, dispensing | `pharmacies`, `pharmacy_branches`, `pharmacy_staff`, `dispense_requests`, `dispense_events` |
| **Payments** | checkout, webhooks, refunds | `payments`, `payment_webhook_events`, `refunds` |
| **Settlements** | commission ledger, payouts | `settlement_entries`, `payouts` |
| **Notifications** | SMS/email/push/in-app delivery, preferences | `notifications`, `notification_preferences`, `notification_deliveries` |
| **Reviews** | ratings, moderation, reports | `reviews`, `review_reports` |
| **Administration** | admin APIs, complaints, settings, reports | `complaints`, `platform_settings` |
| **Audit** | append-only audit trail, integration event log | `audit_logs`, `integration_events` |

### Dependency direction

```
Support ◄── every module
Audit   ◄── every module (AuditLogger)
Identity ◄── Patients, Doctors, Pharmacies, Administration
Specialties ◄── Doctors
Doctors, Patients ◄── Scheduling ◄── Consultations ◄── Messaging, Video, MedicalRecords, Prescriptions, Reviews
Prescriptions ◄── Pharmacies (dispensing)
Scheduling ◄── Payments ◄── Settlements
Notifications: listens to events from all modules; no module depends on it except via SmsProvider/notify()
```

Cycles are not allowed. When a lower module needs to react to a higher one, it
listens to an event.

## Key decisions

| Decision | Rationale |
|---|---|
| UUID (v7, time-ordered) primary keys on every domain table | Safe to expose in URLs, no enumeration, index-friendly ordering |
| Bearer tokens for both web and mobile (Sanctum PATs) | One auth path; no cookies → no CSRF surface; per-device revocation |
| Tokens double as device registry (device id, platform, IP, last use) | Device/session management without another table |
| MFA gating via token *abilities* (`mfa:challenge`, `mfa:enroll`, `*`) | A password alone never yields a full token for privileged roles |
| PostgreSQL-specific constraints (CHECK, exclusion, triggers) | Integrity enforced by the database, not only by PHP |
| Tests run on PostgreSQL | Exclusion constraints, row locks and triggers must be exercised |
| Append-only `audit_logs` enforced by trigger | Application code cannot rewrite history |
| Clinical text encrypted with Laravel `encrypted` casts (AES-256) | Protects data at rest beyond disk encryption; key rotation via `APP_PREVIOUS_KEYS` |
| Money as integer halalas (`*_amount` bigint) + ISO currency | No floating-point errors |
| Immutable ledger for settlements | Balances are derived, never overwritten |
| Every external integration behind an interface + mock driver | Runs locally with zero paid services; nothing real is claimed |
| Soft deletes for accounts, restrictive FKs for clinical/financial data | Deleting an account never cascades into records the law requires us to keep |

## Integration interfaces

| Interface | Default driver | Purpose |
|---|---|---|
| `Notifications\Contracts\SmsProvider` | `log` / `fake` | OTP and notices by SMS |
| `Doctors\Contracts\ProfessionalVerifier` | `manual` | SCFHS licence verification (manual admin review until authorised) |
| `Video\Contracts\VideoProvider` | `mock` | WebRTC sessions + short-lived participant credentials |
| `Payments\Contracts\PaymentGateway` | `mock` | Checkout, webhook signature verification, refunds |
| `Prescriptions\Contracts\PrescriptionGateway` | `mock` | Submission to an authorised e-prescribing service |
| `Prescriptions\Contracts\ClinicalSafetyChecker` | `mock` | Interaction/allergy checks via a clinically validated source |
| `Pharmacies\Contracts\PharmacyGateway` | `mock` | Pharmacy system integration |
| `Messaging\Contracts\FileScanner` | `mock` | Malware scanning of uploads |
| `Notifications\Contracts\PushProvider` | `log` / `fake` | Mobile push |
