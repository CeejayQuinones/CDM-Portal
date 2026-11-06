# Enrollment Phase 1.4 — Editable Student Profile Contract Audit

## Outcome

The requested editable Student Info workflow cannot be implemented safely on the current schema without an approved migration and backend contract. No local-only or prematurely authoritative imitation was added.

The existing Enrollment application stores academic identity, classification, status, version, review information, documents, section/load state, and timestamps. It has no field or related table for a personal-information draft or immutable submitted profile snapshot. Its versioned `save` action accepts only `classification`. Registrar approval marks the application `approved` but does not synchronize a profile.

Replacing the current cards with inputs before that contract exists would violate the requirements in two ways:

1. values would disappear after reload because there is nowhere legitimate to persist them; or
2. saving would have to update `user_profiles` immediately, making unreviewed draft data authoritative before Registrar approval.

Both outcomes are explicitly prohibited. Phase 1.4 therefore stops at the documented approval boundary.

## Current versus intended workflow

### Current

1. Registration creates a `users` login account and a `user_profiles` row.
2. Admission may create an accepted applicant and verified academic handoff.
3. Enrollment creates one versioned application per owner and academic term.
4. Draft saving persists classification only.
5. Submission freezes application editing and sends configured documents for review.
6. Registrar review and approval update application status and review metadata.
7. Academic finalization separately creates/updates Student and Enrollment records.

### Intended after approval

1. A draft application receives a private, versioned personal-information snapshot.
2. Student edits and saves that snapshot through the existing application concurrency boundary.
3. Submission freezes the exact validated snapshot.
4. Registrar reviews the snapshot and its field-level differences from the current official profile.
5. The existing `under_review → approved` transition locks the application, owner, and profile.
6. One transaction applies only approved mapped fields, records a non-sensitive audit summary, and marks the application approved.
7. Authentication credentials, Google identity, Student number, section, academic status, and grades remain untouched.

## Data ownership audit

| Data | Current owner | Enrollment draft support | Intended Phase 1.4 behavior |
| --- | --- | --- | --- |
| Username/password | `users` | None | Immutable; never copied into a snapshot |
| Google subject/email | `users` | None | Authentication identity; never changed by Enrollment |
| Legal names | `user_profiles` | None | Prefill draft; synchronize only after approval |
| Birth date/gender | `user_profiles` | None | Prefill draft; synchronize only after approval |
| Personal/contact email | `user_profiles.email` | None | Distinct from login credentials; changed values require verification |
| Contact number | `user_profiles.contact_number` | None | Prefill draft; synchronize only after approval |
| Address | One `user_profiles.address` text field | None | Store components in application snapshot, compose official address on approval |
| Classification | `enrollment_applications.classification` | Versioned draft | Already supported |
| Program/curriculum/year | Application + Student/Admission handoff | Server-derived | Remain read-only and eligibility-controlled |
| Student ID/section | `students` / academic finalization | Not Student editable | Never included in the personal draft |
| Documents | `enrollment_documents` and verified Student documents | Versioned upload | Existing behavior retained |

## Reference field mapping

| Reference field | Proposed draft field | Official mapping after approval | Rule |
| --- | --- | --- | --- |
| First Name | `first_name` | `user_profiles.first_name` | Required, string, max 100 |
| Middle Name | `middle_name` | `user_profiles.middle_name` | Nullable, string, max 100 |
| Last Name | `last_name` | `user_profiles.last_name` | Required, string, max 100 |
| Birthday | `birth_date` | `user_profiles.birth_date` | Required, valid date before today |
| Age | Derived only | None | Never persisted or manually editable |
| Gender | `gender` | `user_profiles.gender` | Existing enum only |
| Contact Number | `contact_number` | `user_profiles.contact_number` | Required, existing maximum plus validated phone format |
| Email Address | `contact_email` | `user_profiles.email` | Required; changed value needs verified-email proof |
| Province | `address.province` | Part of composed `user_profiles.address` | Required free text until an approved complete dataset exists |
| City/Municipality | `address.city_municipality` | Part of composed address | Required free text |
| Barangay | `address.barangay` | Part of composed address | Required free text |
| House/Street/Subdivision | `address.address_line` | Part of composed address | Required free text |
| Full Address | Derived `full_address` | `user_profiles.address` | Server recomputes; client preview is not authoritative |
| Year Level | Existing application field | No profile write | Derived from verified academic eligibility |

Student ID, section, academic status, and course/curriculum assignment remain excluded from the editable payload.

## Address data source

No complete, maintained Philippine geographic dataset or package exists in this repository. The collaborators' `PHILIPPINES_ADDRESS_DATA` is an incomplete static list and must not be presented as comprehensive.

The existing Guest registration wizard already uses validated free-text Province, City/Municipality, Barangay, and address-line fields, then composes them into the single supported profile address. The safe Phase 1.4 design should use the same free-text fallback until a separately reviewed Philippine Standard Geographic Code dataset, update process, attribution, and stable identifiers are approved.

Dependent searchable selectors must not be added using the incomplete reference constants. If a complete dataset is later approved, Province changes must clear invalid municipality and barangay identifiers, and Municipality changes must clear an invalid barangay identifier.

## Required persistent contract

### Proposed migration for explicit approval

Add to `enrollment_applications`:

- `profile_snapshot` JSON, nullable;
- `profile_snapshot_version` unsigned integer, nullable;
- `profile_base_updated_at` timestamp, nullable;
- `contact_email_verified_at` timestamp, nullable;
- `profile_synced_at` timestamp, nullable.

The snapshot JSON should have a versioned allowlisted shape:

```json
{
  "schema_version": 1,
  "first_name": "...",
  "middle_name": null,
  "last_name": "...",
  "birth_date": "YYYY-MM-DD",
  "gender": "Male",
  "contact_number": "...",
  "contact_email": "...",
  "address": {
    "address_line": "...",
    "barangay": "...",
    "city_municipality": "...",
    "province": "..."
  },
  "full_address": "..."
}
```

Do not include passwords, verification tokens, authentication identifiers, Student numbers, sections, grades, or document contents.

### Proposed API changes

- Application creation: prefill the snapshot from the current profile but do not mutate the profile.
- Existing `save` action: accept `classification` and a validated `profile` object; update both under the current application `version` lock.
- Existing `show` action: return only the authorized owner's snapshot and derived readiness.
- Existing `submit` action: revalidate the complete snapshot, changed-email verification, eligibility, classification, and configured documents.
- Existing Registrar index/show: include the submitted snapshot and a safe field-level comparison for review.
- Existing `approve` action: synchronize the frozen snapshot transactionally.

No duplicate endpoint or second Enrollment record is needed. Existing owner/term unique constraints remain authoritative.

## Draft persistence and validation

Draft fields must be saved only through the versioned Laravel application endpoint. Browser state can support step navigation but cannot be the sole persistent copy.

Server validation must enforce:

- required trimmed first and last names;
- optional normalized middle name;
- birth date before today without inventing a minimum-age policy;
- the existing gender enum;
- required contact number with an agreed Philippine/international-compatible format;
- required valid contact email;
- all four address components and a server-composed full address;
- the existing classification enum;
- server-derived program/curriculum/year eligibility;
- configured required documents at submission.

Laravel validation errors should be returned by field path and displayed beside the corresponding Website form control without clearing entered values.

## Contact email and authentication isolation

`users.username`, `users.password`, `users.google_subject_id`, and `users.google_email` are authentication data. The enrollment `contact_email` maps only to `user_profiles.email`, which is also used for supported notification delivery.

When the draft contact email differs from the current verified profile email, the existing email code mechanism may be reused only through an Enrollment-specific authenticated flow. The one-time token must be consumed while saving/verifying the draft, and only a normalized email plus verification timestamp may be stored. A raw code or token must never enter the application snapshot.

Because the existing registration token expires after ten minutes, it cannot be deferred until a later Registrar approval. Approval relies on the recorded verification fact for the frozen submitted snapshot.

## Registrar approval transaction

The existing authorized Registrar `approve` transition is the correct synchronization boundary. Inside its existing database transaction:

1. lock the application and verify `under_review`, owner, submitted snapshot version, and request version;
2. lock the owner User and `user_profiles` row;
3. confirm the snapshot is complete and its changed contact email was verified;
4. compare `profile_base_updated_at` with the official profile and return `409` if the profile changed after submission, requiring explicit re-review rather than silent overwrite;
5. update only first, middle, and last name, gender, birth date, contact number, personal email, and composed address;
6. never modify User credentials, Google identity, roles, Student number, course, curriculum, year, section, status, grades, or documents;
7. set `profile_synced_at`, application status, reviewer, notes, reviewed time, and incremented version;
8. write an Enrollment audit event containing the application/snapshot version, a snapshot hash, and changed field names—but no PII values;
9. commit all changes together or roll everything back.

Repeated approval remains rejected by the current state machine. A transaction retry must be idempotent through the locked application status and `profile_synced_at` invariant.

## Post-approval and correction behavior

Submitted, under-review, approved, and finalized snapshots remain read-only to the Student. The current state machine has no “returned for correction” transition. Rejection currently does not reopen a draft, so Phase 1.4 must not claim correction support unless a separately approved transition is added.

After approval, Website profile displays can use the synchronized `user_profiles` data. Academic finalization remains separate and retains its existing Student identity and enrollment safeguards.

## Frontend implementation after contract approval

The Website Student step should replace read-only cards with:

- three-column desktop name fields;
- birth date, derived read-only age, gender, and read-only eligible year level;
- free-text address components and generated full-address preview;
- contact number and separately labeled Enrollment contact email;
- field-level server errors and accessible required state;
- Save draft before leaving the step;
- a final review with Edit actions and explicit Registrar-verification notice.

At 768 px and below the form becomes one column. Android retains its existing presentation, while the shared API can return the approved official profile consistently after synchronization.

## Current executable changes

None. The current read-only cards were intentionally preserved until the persistent contract is approved. Adding editable inputs now would falsely imply that the Portal saves and reviews those values.

No migration file was created or executed. No backend endpoint, database record, `.env`, Android view, Electron view, COR, Admission Exam, or Grading workflow was changed.

## Validation status

- Repository/data-ownership audit: completed.
- Reference `Enroll.tsx` field and interaction audit: completed against revision `b67543eb30c69548694659689f4e51a1b9d3dcb0`.
- Phase 1.4 executable regression tests: not applicable until the migration/API contract is approved.
- Full frontend/backend/build matrix: not rerun for this documentation-only boundary; the immediately preceding Phase 1.3 baseline passed 76 frontend files, 477 Laravel tests / 4,570 assertions, and Website/Android/Electron builds.
- `git diff --check`: passed.

## Manual UAT checklist after implementation

- Create and reload a draft; confirm every personal field returns from the backend snapshot.
- Modify prefilled values and confirm the official profile remains unchanged before approval.
- Verify birth-date boundaries and derived age around birthdays.
- Verify free-text address validation, composition, reload, and review display.
- Confirm changed email requires verification and does not change username, password, or Google identity.
- Navigate between all three steps without losing saved or unsaved values.
- Trigger field-level server validation and confirm input remains intact.
- Submit, then confirm all personal fields become read-only.
- Confirm unauthorized users and Professors cannot read or mutate another Student's snapshot.
- Review and approve as Registrar; confirm only allowlisted profile fields synchronize.
- Simulate a concurrent official-profile edit and confirm approval returns a conflict without partial writes.
- Retry approval and confirm no duplicate synchronization or audit event.
- Confirm Student ID, section, course, curriculum, academic status, grades, and COR remain unchanged.
- Verify Website layouts at 1440, 1024, 768, 390, and 320 px in Light, Dark, and System themes.
- Confirm Android and Electron presentation remains unchanged.

## Approval required

Approval is required before creating the migration and implementing the versioned snapshot/save/submit/approval synchronization contract described above. Once approved, backend persistence and transactional tests should be implemented before exposing editable Website controls.

