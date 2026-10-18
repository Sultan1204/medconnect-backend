# Compliance requirements register

**Technical implementation is not regulatory approval.** This register lists
what must be confirmed with qualified legal/regulatory advisers and the
authorities before real patients, real prescriptions or real money are involved.
Nothing in the code claims compliance, certification or access to government
systems. Every integration runs on a mock adapter.

Status: *Mock* = adapter only · *Open* = needs decision/approval · *Technical control* = implemented, still needs legal validation.

| # | Area | Requirement to confirm | What the code does today | Status |
|---|---|---|---|---|
| 1 | Health-practitioner licensing (SCFHS) | Verification of licences; rules for tele-practice | Manual admin review; `ProfessionalVerifier` interface; expiry job | Mock / Open |
| 2 | Telehealth rules (MOH) | Licensing of the platform operator and permitted consultation types | Consultation types configurable; no claim of authorisation | Open |
| 3 | E-prescriptions (e.g. Wasfaty) | Authorised issuance, controlled-drug rules | Draft/approval workflow against `PrescriptionGateway` mock; no real prescribing | Mock / Open |
| 4 | Pharmacy dispensing | Integration with licensed pharmacies; dispensing records | `PharmacyGateway` mock; simulated dispensing flag | Mock / Open |
| 5 | Payments (SAMA) | Licensed payment provider, marketplace/split rules, refund obligations | `PaymentGateway` mock, hosted checkout, no card data; webhook-driven | Mock / Open |
| 6 | Payouts to doctors | Provider capability and regulatory review | Prepared only; execution disabled (`PAYOUTS_ENABLED=false`) | Open |
| 7 | Personal data protection (PDPL) | Lawful basis, consent wording, DPO, breach process, cross-border transfer | Versioned consent, data-subject request intake and handling API, encryption, audit trail | Technical control / Open |
| 8 | Data residency / hosting | Where health data may be stored and processed (incl. video, SMS vendors) | Provider-agnostic; nothing chosen | Open |
| 9 | Retention and deletion | Retention periods for clinical, financial, audit records | No automatic deletion; restrictive FKs; deletion requests are decided by people | Open |
| 10 | Cybersecurity (NCA controls) | Applicability and assessment | See [security.md](../security.md) | Open |
| 11 | SMS sender ID (CST) | Registered sender names and templates | `SmsProvider` interface; log/fake drivers | Mock / Open |
| 12 | Clinical safety | Red-flag screening content, emergency guidance, interaction checking source | Configurable screening; `ClinicalSafetyChecker` mock | Open (clinical governance) |
| 13 | Medical records content | Mandatory record content and amendment rules | Versioned notes, attributed amendments | Technical control / Open |
| 14 | Consumer protection / ratings | Rules on reviews, fees display, cancellation terms | Moderated reviews, snapshotted cancellation policy | Open |
| 15 | Electronic invoicing (ZATCA) | Invoice format and VAT | Not implemented | Open |
| 16 | Terms, privacy notice, consent text | Arabic/English legal texts | Placeholders only | Open |
| 17 | Complaint handling | Response-time obligations | Complaint intake, SLA-agnostic handling and history | Technical control / Open |

Owners and dates are to be assigned by the project sponsor.
