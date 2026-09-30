# Admission identity foundation — Step 3

Implemented only the two identity tables from ADMISSION_SCHEMA_DESIGN.md through
additive migrations. No migration was applied to the existing local portal database;
`php artisan migrate --pretend` previews the pending SQL. There is no API endpoint,
frontend access change, seed data, Student creation/conversion or exam workflow.

## Schema

| Table | Columns |
| --- | --- |
| admission_cycles | id, code (16), name, academic_year_id, status, opens_at, closes_at, confirmation_closes_at, policy_version, exam_policy (nullable JSON), created_by_user_id, updated_by_user_id, created_at, updated_at |
| admission_applicants | id, user_id, cycle_id, applicant_number (40), status, version, preferred_course_id, accepted_course_id, accepted_curriculum_id, converted_student_id, profile_snapshot, snapshot_version, verified_contact_email, contact_verified_at, submitted_at, accepted_at, rejected_at, withdrawn_at, expired_at, converted_at, source_system, source_applicant_id, legacy_applicant_number, created_at, updated_at |

Cycles have a unique code; indexed academic year/status and status/date window;
indexed actor FKs. Applicants have unique applicant number, user/cycle pair, nullable
converted Student link and source-system/source-ID pair. Lookup indexes cover
cycle/status/submitted date, user/creation date, legacy number and academic FKs.
All nine FKs use RESTRICT on deletion. No existing table is altered or recreated.
No soft deletes. Dates use portal timestamp conventions; deploy with the approved
timezone configuration before exposing intake.

Application states: draft, submitted, under_review, accepted, rejected, withdrawn,
expired, converted. Cycle states: draft, open, closed, archived. Constants are owned
by the corresponding models. Migrations freeze their allowed values independently
of future runtime model edits. This uses the project's existing enum-column
convention (native enum on MySQL, checked text on SQLite).

The reserved academic, snapshot, verified-contact and provenance columns are
nullable and never populated by the creation service. Conversion status/link/time
consistency and provenance pairing are model-level invariants; cross-table workflow
checks and audit-backed transitions remain future work. No conversion relationship
is exposed as though the applicant were enrolled. `exam_policy` is a nullable,
reserved design field: no exam settings, validation rules or engine are activated.
Draft cycle dates may be NULL; opening requires all dates in order. Used cycle codes
and persisted application ownership/number cannot be changed through the models.
Direct query-builder writes bypass model validation; they are not an authorized
identity creation/transition interface.

## Models and service

- `App\Models\Admission\AdmissionCycle`: academicYear, applicants, createdBy, updatedBy.
- `App\Models\Admission\AdmissionApplicant`: user, cycle.
- Existing User gains `admissionApplications()`; existing user/profile/Student
  tables, auth behavior and existing relationships are unchanged.
- `AdmissionIdentityService::findForCycle(User, AdmissionCycle)` looks up only the
  authenticated caller's case, with fresh persisted account/role authorization.
- `create(User, AdmissionCycle)` creates only a draft. It requires active Guest,
  existing profile, no academic Student record and an open intake window. It takes
  no frontend attributes, official student number or alternate owner ID payload.

An applicant number is `APP-` plus a server-generated UUID (40 characters). The
model generates it on creation regardless of a caller-supplied number; a database
unique constraint is authoritative. The service retries only number collisions,
up to three attempts, each in a transaction. Duplicate application errors are not
converted into successful creation or allowed to create another case.

Creation locks User, then cycle and rechecks persisted values. The user/cycle unique
constraint applies even to closed historical cases, exactly as Step 2 proposed.
The service additionally blocks draft/submitted/under_review/accepted cases across
cycles while holding the User lock. An existing inactive case is preserved rather
than overwritten. This is stricter than merely one active row per cycle, retaining
Step 2's explicit history/one-open-case rules. Future mutation paths must follow the
same lock order; no production mutation endpoints are exposed in this phase.

## Authorization boundary

Explicit Gate policy registrations are added for the two Admission models.

| Role | Prepared ability |
| --- | --- |
| Active Guest | Create identity; view/find own cases only |
| Active Student | Read own pre-existing history; cannot create a new application |
| Professor | No applicant access |
| Active Registrar Staff | view/viewAny applicant read boundary; approval/review mutations are not implemented |
| Active Admin | configure AdmissionCycle boundary; no implicit applicant read, conversion or publication power |
| Inactive/suspended account | No Admission ability |

Policies do not create a new role, auth guard or SPA. Existing Step 1 Guest frontend
blocking remains unchanged until an explicitly authorized self-service increment.
Do not use role-level UI visibility as a substitute for server-side ownership.

## Validation and limits

Focused service/model tests cover profile/User reuse, no academic Student creation,
relationships, duplicate and cross-cycle blocking, distinct identities, forced UUID
collision/retry, caller-number replacement/immutability, DB uniqueness, role and
ownership isolation, stale suspended accounts, invalid cycle windows, missing
profiles, invalid states, deletion restrictions and additive migration up/down.
Migration tests populate existing users/profiles/students/tokens, then verify those
rows and schemas survive the new tables' up/down operations in in-memory SQLite.

The full existing suite uses its configured in-memory test database; no destructive
command is run against local portal data. SQLite tests do not prove simultaneous
MySQL row-lock scheduling. Production concurrency protection relies on the MySQL
transaction locks plus unique indexes; a multi-connection MySQL race test remains
appropriate before enabling a production creation endpoint.

No business-policy divergence from Step 2: only draft cycle dates and reserved
future fields are explicitly nullable for this foundation; later audit-dependent
workflows and JSON policy validation remain deferred. No imports, notifications,
review/publication, conversion, or recommendations were added.

## Recommended Step 4

Authorize a narrowly scoped, ownership-checked Guest self-service identity view and
Admission-only navigation update. Decide whether Step 4 is read-only or includes
creation: production case mutations should wait for the separately approved audit
foundation identified in Step 2. Keep Student creation and the exam engine out of
that increment. Review the MySQL concurrency test and audit requirements first.

## Recorded validation (2026-09-19)

- `php artisan migrate --pretend`: passed against the existing MySQL database;
  only the two Admission migrations were pending. Sandbox connection initially
  failed; the approved retry outside the sandbox succeeded. No migrations applied.
- `php artisan test --filter=AdmissionIdentityTest`: 16 passed, 125 assertions.
- `php artisan test`: 160 passed, 1,093 assertions.
- `vendor/bin/pint --test`: passed.
- `npm test`: all 10 test files passed.
- `npm run build`: passed.
- `git diff --check`: passed; new untracked files separately checked for whitespace.
