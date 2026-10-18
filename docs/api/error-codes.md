# API conventions

- Base path: `/api/v1`. JSON only (`Accept: application/json` is forced).
- Authentication: `Authorization: Bearer <token>`.
- IDs are UUIDs. Times are ISO-8601 in UTC (`2026-10-18T09:30:00Z`); clients
  convert to `Asia/Riyadh` for display.
- Money: integer minor units (halalas) with an ISO currency, e.g.
  `{"amount": 15000, "currency": "SAR"}` = 150.00 SAR.
- Success bodies wrap payloads in `data`. Lists add `links` and `meta` (pagination).
- Pagination: `?page=2&per_page=20` (max 100). Sorting: `?sort=-created_at`.
- Every response carries `X-Request-Id`; quote it when reporting problems.
- Mutating endpoints that create charges accept an `Idempotency-Key` header.

## Error envelope

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": { "fields": { "phone": ["The phone field format is invalid."] } },
    "request_id": "0b5c…"
  }
}
```

`code` is stable and safe to branch on; `message` is human-readable and may change.

## Codes

| HTTP | code | Meaning |
|---|---|---|
| 400 | `http_error` | Malformed request |
| 401 | `unauthenticated` | Missing, invalid or expired token |
| 401 | `invalid_credentials` | Wrong identifier/password (never says which) |
| 403 | `forbidden` | Authenticated but not allowed (policy/role) |
| 403 | `account_inactive` | Account suspended or deactivated |
| 403 | `phone_not_verified` | Complete phone verification first |
| 403 | `mfa_required` | Token is MFA-pending; call `POST /auth/mfa/challenge` |
| 403 | `mfa_enrollment_required` | Role requires MFA; call `POST /auth/mfa/setup` |
| 403 | `mfa_mandatory` | MFA cannot be disabled for this role |
| 403 | `already_authenticated` | A full token was sent to the MFA challenge endpoint |
| 404 | `not_found` | Resource missing *or* not visible to the caller |
| 405 | `method_not_allowed` | |
| 409 | `mfa_already_enabled` | |
| 422 | `validation_failed` | See `details.fields` |
| 422 | `invalid_otp` | Code wrong, expired, exhausted, or account unknown |
| 422 | `invalid_mfa_code` | TOTP / recovery code rejected |
| 422 | `invalid_password` | Current password incorrect (signed-in flows) |
| 422 | `email_unavailable` | Email cannot be used |
| 422 | `email_not_pending_verification` | |
| 422 | `mfa_not_enabled` | |
| 429 | `too_many_requests` | Rate limit hit; see `Retry-After` |
| 429 | `too_many_attempts` | Account temporarily locked after failed sign-ins |
| 500 | `server_error` | Unexpected; details are never exposed |

Module-specific codes are added below as modules are implemented.
