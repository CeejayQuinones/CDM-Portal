# Admission MVP implementation

The Admission MVP extends Step 7 inside the existing portal. Users authenticate
through existing Sanctum User accounts. Guests remain Guests throughout Admission;
no academic Student creation, role conversion, course assignment, or enrollment is
performed. Monitoring, Document Requests, Professor features, academic Student
workflows and unrelated Registrar modules are unchanged.

## Architecture and source contract

Admission services own configuration, exam state, result review and guidance.
Controllers validate requests and serialize domain-specific projections. Active
account/role checks run on the backend for every Admission workflow request.
Admission-only exception handling suppresses database errors even with debug enabled.
The existing layout, navigation, API client and Registrar password step-up are reused.

Reference repository:
`/mnt/f/frm_Chrome/CAPSTONE 1/AMISSION/cdm-career-recommendation-system`.

Behavior was checked against current `docs/OPERATIONS.md`, `ExamSessionService`,
`ExamSessionController`, `ExamResult`, `ResultReviewController`,
`AdminManagementController`, `ResultController`, `ProgramMatcher` and
`ProgramRecommendationAI`. Source matcher and provider logic are copied into the
Admission namespace, with separate Admission configuration. No Monitoring AI service
or key is used.

## Database and migration

Migration: `2026_09_24_000001_create_admission_workflow_tables.php`.
Applied locally in **batch 13**. The existing identity migrations remain in batch 11
and the original audit migration remains in batch 12.

Nine new tables:

| Table | Purpose |
| --- | --- |
| admission_workflow_events | Append-only configuration/exam/result events; supports system actor and non-applicant subjects |
| admission_program_settings | Admission metadata on existing academic courses |
| admission_exam_questions | Managed five-topic bank with versioned questions |
| admission_exam_sessions | UUID attempt, deadline, policy snapshot, revision and position |
| admission_exam_session_questions | Immutable assigned order, text, options and private key snapshot |
| admission_exam_answers | One latest accepted answer per assigned snapshot |
| admission_exam_results | Immutable system grading evidence and versioned official projection |
| admission_decisions | Append-only Registrar decision history, before/after projection and private reason |
| admission_recommendations | Per-result/version/fingerprint guidance, catalog and scoring evidence |

The existing `admission_cycles`, `admission_applicants` and
`admission_audit_events` schemas were not altered. The existing audit writer still
writes identity events to its original table. Its new allowlisted workflow method
writes the new event table because cycles have no applicant and expiry has no human
actor. This avoids manufacturing applicant identities or changing the existing audit
contract. Answer keys, complete answers, credentials and raw request payloads are
excluded from audit metadata. Private Registrar reasons live in restricted decisions,
not applicant responses.

Only additive DDL was applied after inspected `migrate --pretend` runs. MariaDB
initially rejected a generated index name and the second required TIMESTAMP default
in the new session table. The migration now uses short event indexes and explicit
DATETIME start/deadline fields. Safe guards resume the new empty configuration tables
left by those attempts. No tables/data were dropped to recover. No destructive
migration command or production seeder was run.

## API routes

All paths below are under `/api/admission`.

| Method / path | Access and purpose |
| --- | --- |
| GET me | Guest/Student safe own identity |
| GET applications/availability | Own creation availability |
| POST applications | Eligible Guest; server-selected cycle and identity |
| GET admin/cycles | Admin cycle list and academic-year choices |
| POST admin/cycles | Admin create |
| PUT admin/cycles/{id} | Admin edit/open/close with expected_updated_at |
| GET admin/questions | Admin paginated/searchable bank and topic counts |
| POST admin/questions | Admin create |
| PUT admin/questions/{id} | Admin edit/status change with version |
| DELETE admin/questions/{id} | Admin delete unused question with version |
| GET admin/programs | Admin academic catalog and Admission settings |
| PUT admin/programs/{courseId} | Admin settings/version update; no academic master mutation |
| GET exam | Own eligibility/status; Guest expired session finalization |
| POST exam/start | Guest start/resume |
| PUT exam/{uuid}/answers | Guest save full assigned-answer map and revision |
| POST exam/{uuid}/submit | Guest save/submit idempotently |
| GET result | Own latest result, only after publication |
| GET recommendation | Published-result guidance/read |
| POST recommendation | Guest interest ratings and provider-backed guidance |
| GET registrar/applicants | Registrar search/status/cycle filters |
| GET registrar/results | Registrar result search/status/cycle/latest filters |
| GET registrar/history | Registrar decisions and recent workflow events |
| POST registrar/results/approve | Versioned individual or batch approval |
| POST registrar/results/publish | Versioned individual or batch publication |
| POST registrar/results/correct | Latest published correction; password step-up |
| POST registrar/results/override | Eligible second-failure exceptional pass; password step-up |

Registrar mutation payloads carry `results: [{id, version}]`, optional
`official_score` and private `reason`. Batches are atomic, bounded to 100, lock
applicant users in deterministic order and reject stale selections. Approval without
an explicit score uses rounded system percentage.

Admin is the portal's existing Admin role (also called System Admin in business
requirements). Admin cannot review/publish results. Registrar cannot configure
cycles/questions/programs. Professor has no Admission access. Students only read
their own historical Admission records and cannot start, save, submit or regenerate
guidance. Suspended/inactive accounts are denied. IDs never override ownership.

## Cycle and application workflow

Admin manages `/admin/admissions/cycles`, using existing academic years. Cycle
statuses are draft/open/closed/archived. Opening requires ordered application and
confirmation dates. Browser ISO timestamps normalize to the application timezone.
Overlapping open windows are rejected. A stable lock on the existing Admin role row
serializes configuration operations, including first-cycle creation; the role is
never modified. Cycle updates use existing updated_at as their conflict token.

Guest creation remains the audited Step 7 service: exactly one currently open cycle,
existing profile, no Student record, no duplicate user/cycle or active cross-cycle
case, server-generated UUID applicant number, User-then-cycle locks and atomic audit.
Closing a cycle stops new applications; it does not reset or cancel an active exam.
No separate exam-window policy is invented because the identity schema has no such
window fields. The saved draft identity is sufficient to enter the exam workflow.

## Exam lifecycle and UI

`/admission/exam` offers instructions/eligibility, start or resume, current topic,
question navigation, previous/next, unanswered count, autosave/manual save status,
deadline display and confirmed submission.

- Five exact source topics: General Mathematics, Science, Reading Comprehension,
  Logical Reasoning and Digital Literacy.
- Select 20 active questions per topic, 100 total. Shuffle topic order and selection/
  order within each topic; topics stay contiguous. Larger banks are supported.
- Start fixes a server deadline 120 minutes later and stores ordered question/key
  snapshots plus a bank hash. Repeated start and resume return the same session.
- Server saves require a matching revision and the exact assigned-answer map.
  Another device's update produces 409; reload authoritative answers to continue.
- UI autosaves while connected, blocks concurrent submit/save, indicates unsaved
  changes and warns before leaving with unsaved work. There is no persistent offline
  answer backup in this MVP. Reconnect/save before expiry; refreshing discards
  unsent edits but restores accepted answers and the original deadline.
- At expiry, only previously accepted answers are graded. Late save/submit returns
  the completed/expired state without applying its answers. Expiry is also handled
  on status/start and by the scheduler.
- Repeated submission returns the finalized session without another result.
- System grading stores correct count, percentage, category counts/maximums and
  bounded elapsed seconds. Passing threshold is 75.
- Applicant responses never contain answer keys, correctness flags or unpublished
  result evidence. Completed-session responses omit questions and answers.

All exam/result mutation paths serialize on the applicant's User row, then lock
relevant domain rows. Bank configuration and start additionally share the Admin-role
configuration lock. Session/result uniqueness is also enforced in the database.

## Attempts, Registrar and publication

Two attempts maximum **per User**, preserving the source lifetime rule. Reapplying
in another cycle does not reset the allowance. A second attempt requires the first
result to be published as RETAKE (official score below 75 without exceptional pass).
Starting/resuming an active attempt does not allocate another. There is no third.

Registrar pages:

- `/registrar/admissions`: search and inspect applicant identity/status.
- `/registrar/admissions/results`: attempts, system/category evidence and release status.
- `/registrar/admissions/review`: individual/batch approve and publish.
- `/registrar/admissions/history`: immutable decision history and workflow events.

Publication is separate from approval. Only the latest completed attempt can be
changed; an active retake blocks prior-result correction. Every mutation checks the
result version and appends decision/audit evidence in the transaction.

Exceptional pass requires a failed second system attempt, failing/null official
score, a previously published eligible first failure and a private reason. It
publishes immediately. A later correction requires a private reason and explicit
official score, removes the override and republishes without changing system
evidence or attempt limits. Overrides/corrections reuse Registrar password step-up.

`/admission/result` displays only the latest published outcome/date/attempt and
allowed next action. Exact score is hidden for exceptional passes. During an active
or unpublished second attempt, no prior result is substituted as the current result.

## Program guidance

`/admission/recommendation` requires the corresponding latest result to be published,
including a published failure as in the source guidance policy. It never means
acceptance or course assignment. Optional five-topic interest ratings are 1–5.

The unchanged source matcher calculates weighted category alignment; with interests,
it uses 80% exam alignment and 20% stated interests. It retains ties, returns
insufficient-evidence states for zero/missing evidence, and does not fabricate a
winner. Technical Aptitude in source profiles maps to Digital Literacy.

Only active/recommendable Admission settings on active portal courses participate.
Reference catalog metadata is retained in
`backend/resources/admission/reference-programs.json`. Local setup copies settings
only for exact code matches; the current local database matches BSIT. Other majors/
courses require explicit Admin configuration; no fuzzy mappings or academic catalog
overwrites occur.

Guidance stores result version, interests, catalog snapshot, fingerprint, rankings,
evidence and explanation. Saved current-version guidance is returned on GET; an
explicit interest update uses the current catalog. Corrections invalidate prior
result-version guidance. The source Gemini explanation provider uses isolated
`ADMISSION_GEMINI_API_KEY` and `ADMISSION_GEMINI_MODEL` (source default
`gemini-3.5-flash`). It cannot change deterministic rankings. Missing key/provider
failure retains calculated guidance and reports explanation unavailable.
Live external-provider output has not been integration-tested.

Exceptional-pass projections omit exact evidence, alignment scores and cached AI
explanations. Internal Registrar reasons are never sent to applicants.

## Scheduler

Registered with the existing Laravel scheduler:
`admission:finalize-expired`, every minute, without overlapping runs.

Local development (working directory backend):

```sh
php artisan schedule:work
```

Production: run `php artisan schedule:run` every minute from the backend directory
using the hosting scheduler/cron. Do not run both scheduler mechanisms on the same
instance. Manual recovery/verification:

```sh
php artisan admission:finalize-expired
php artisan schedule:list
```

Finalization is idempotent. Existing Document Request scheduling is unchanged.

## Local data and browser walkthrough

No complete approved production question bank was found in the reference repository.
Search covered seeders, migrations, JSON/CSV/XLSX/TXT/SQL/database files, docs, tools,
tests and fixtures. The current seeder contains five samples; one migration adds
eight questions and another only reactivates pre-existing retained data. Synthetic
load-test fixtures are not an approved production bank.

The user's expressly authorized local dummy setup is manual only:

```sh
cd /home/kenneth/projects/CDM_Portal/backend
php artisan admission:dev-setup --dummy-bank
```

Requires local/testing environment and an existing active Admin/academic year.
Refuses production and refuses adding dummy questions alongside a non-development
bank. Creates exactly one labeled DEV-MVP cycle and 100 labeled DEV-MVP questions,
20 per topic, all with answer A. Repeated execution does not duplicate them. It
creates no users or academic Students. Production selection excludes DEV-MVP
question codes even if accidentally left active. Local data is installed and retained
for manual testing: one open DEV-MVP cycle, five groups of 20 active dummy questions,
and exact-reference BSIT program settings.

After testing, explicit cleanup:

```sh
php artisan admission:dev-setup --cleanup
```

Cleanup targets only DEV-MVP question codes and the DEV-MVP cycle. Unused questions
are deleted; questions already in snapshots are retired because historical evidence
must remain. The cycle closes. Existing applicant/session/result/audit evidence and
approved program settings are retained. It does not reset attempt allowances or
delete accounts/Students. Reopen a retained test cycle through Admin when needed.
**Cleanup was tested in disposable SQLite only; local DEV-MVP data was not removed.**

Start the normal portal backend/frontend (or use your existing running processes):

```sh
# Terminal 1, backend
php artisan serve --host=127.0.0.1 --port=8000
# Terminal 2, CDM_Frontend; explicit API URL avoids stale LAN .env values
VITE_API_BASE_URL=http://127.0.0.1:8000/api npm run dev -- --host 127.0.0.1
# Terminal 3, backend
php artisan schedule:work
```

Open the Vite URL shown in terminal (normally http://127.0.0.1:5173).
Routes use hash routing, for example `/#/admission`.

1. **Admin:** sign in using an existing Admin account. Admission → Admission Cycles
   shows DEV-MVP. Edit dates/status; leave it open. Question Bank shows 20 per topic;
   edit/retire questions as needed while keeping the bank complete. Program
   Configuration shows the academic catalog; BSIT has source-derived active settings.
2. **Guest:** sign in/register using the normal portal flow. Admission Status →
   Start Admission Application → Confirm. Record the applicant number. Entrance
   Exam → Start Exam. Select answers, wait for “Saved on server”, refresh to verify
   resume, then Submit exam → Confirm submission.
3. **Registrar:** sign in separately. Applicants shows the new case. Exam Results or
   Review / Publish → search its number → Approve → Confirm, then Publish → Confirm.
   Batch selections can approve using system scores and publish. History shows decisions.
4. **Guest:** refresh Result. View the published outcome, then Recommendation.
   Update interest ratings; without an Admission AI key, deterministic guidance
   remains usable. To test a retake, submit wrong answers on the first attempt,
   approve/publish its system score, then use Start retake. A published second result
   cannot grant a third attempt. Use a separate test Guest for a fresh two-attempt flow.

## Validation and limitations

Focused HTTP tests cover configuration, same-day ISO overlap, roles, bank readiness,
snapshot stability, server saves/revisions, timeout/scheduler, idempotent submission,
grading, publication, retake/exhaustion, correction/override protection and redaction,
batch rollback, provider fallback, production dummy exclusion and dev cleanup safety.
Frontend mounted tests compile the real Vue components and exercise role navigation,
empty/loading/error states, application creation and confirmed exam submission.

The real MariaDB verifier passed after workflow migration: three endpoint races each
returned one 201 and one 409; distinct users, collision retries, bounded exhaustion,
audit rollback, cleanup and all existing schema/data fingerprints passed.
Its final rerun preserves the open DEV-MVP cycle and therefore intentionally skips
endpoint races that require an otherwise empty open-cycle configuration.

Limits: production needs a complete school-reviewed bank; this dummy bank is only
for UI/testing. No stress/load certification or Oracle MySQL 8 verification is claimed.
No persistent offline answer backup, spreadsheet result import or publication-email
outbox is included in this MVP. Applicants read published status in the portal.
Institutional admission decisions and Applicant → academic Student conversion remain
the separate final phase; passing never grants Student privileges.

## Deployment and rollback

Back up the deployment database, inspect `php artisan migrate --pretend`, then run
normal `php artisan migrate` for the additive tables. Configure an approved bank,
academic-course mappings, shared throttle/scheduler cache, correct timezone and the
scheduler. Optional Admission-only AI credentials must be configured separately.
Run backend tests/Pint, frontend tests/build and the database verifier in a quiet
non-production environment before rollout.

Prefer reverting code/turning off Admission entry points over dropping evidence.
Migration down drops only the nine new tables but destroys Admission workflow
history; do not run it on populated deployments without a separately reviewed backup/
retention plan. Never drop identity tables or run destructive seeders to roll back.

## Recorded final validation (2026-09-23)

- Backend: **196 tests passed, 1,492 assertions**.
- Pint: passed.
- Frontend: all **10 test files passed**, including the Admission route/component
  harness and its Admin page-switch regression.
- Production Vite/PWA build: passed (204 modules).
- `git diff --check`: passed.
- MariaDB 10.4.32 / REPEATABLE-READ: final service races, four distinct-user numbers,
  forced collision retry, exactly three-attempt exhaustion, atomic audit rollback,
  exact fixture cleanup and schema/data fingerprints passed. The final endpoint
  race section skipped because DEV-MVP is open; the prior post-migration run
  verified all three endpoint races as one 201 plus one 409.
- Headless Chromium with the real frontend and Laravel API on a separate temporary
  SQLite database passed Admin cycle close/reopen, question edit, program save,
  Guest creation/start/save/refresh/submit, hidden unpublished result, Registrar
  approve/publish, failed-first retake, passed-second recommendation/provider fallback,
  history and third-attempt denial. No page errors. The radio/autosave/submit UI was
  exercised; remaining answer maps were filled through browser HTTP requests to
  avoid 100 repeated manual clicks. Authentication used synthetic fixture tokens,
  so this was not a registration/email or credential-login test. Local MariaDB
  DEV-MVP records were not used or altered by this browser run.

Validation found and fixed only two implementation defects: same-day ISO cycle
date comparison and stale Admin page data during Cycles → Question Bank navigation.
A missing import in the new cleanup test was also corrected. No existing authentication
architecture or unrelated feature was changed.

Implementation files are confined to the Admission module/services/models/tests/
resources, its additive migration and commands, plus Admission-only registrations in
`backend/routes/api.php`, `backend/routes/console.php`, `backend/bootstrap/app.php`
and `CDM_Frontend/src/config/accessControl.js`.

## Final phase: acceptance and academic Student conversion (2026-09-25)

The final conversion workflow is now implemented. This supersedes the earlier
statement that institutional acceptance and conversion remain a separate future
phase. No academic or Admission schema migration is required.

### Mapping and eligibility

`students.user_id` and `user_profile_id` reuse the existing applicant User and its
canonical profile. Names, contacts, username, password, account status and User ID
are unchanged. The Registrar explicitly accepts an active academic course and an
active curriculum belonging to that course. Acceptance is recorded in the existing
`admission_decisions` table against the exact application/result versions, and sets
`accepted_course_id`, `accepted_curriculum_id`, `accepted_at`, accepted status and
an incremented application version. Profile review is explicitly confirmed by the
Registrar; this operation does not fabricate email-verification evidence.

Conversion requires the latest/current application, an active Guest with a complete
name/profile and no existing academic identity, an open or closed cycle between
its opening and final-confirmation deadline, the latest finalized attempt belonging
to that application, and a published PASSED result (including authorized Registrar
pass). No active attempt across any of the user's cases may remain. Acceptance
must match the current result version and accepted academic IDs. A result correction
invalidates acceptance and requires another explicit review. Closed application
intake does not prevent confirmation within its remaining confirmation window.

The Registrar supplies the official student number: nonempty, at most 20 characters,
no surrounding whitespace/control characters, and database-unique under the portal's
existing collation. There is no production sequence allocator in the portal; seed
example `26-00001` is not treated as an allocation policy. No `MAX+1`, applicant-number
reuse, or new numbering format is introduced. A duplicate number returns a conflict.

Student creation supplies exactly: existing `user_id`, existing `user_profile_id`,
accepted `course_id`, accepted `curriculum_id`, confirmed `student_number`, confirmed
`admission_date` (valid date, not in the future), `year_level = 1`, and
`student_status = regular`. Curriculum must be active and effective no later than
the admission-date year. No enrollment, section, grade, Monitoring, Document Request,
or other academic workflow record is created.

### Transaction and concurrency

Conversion locks the User first, then applicant and relevant cycle/profile/session/
result/acceptance/academic rows, and revalidates inside the transaction. The existing
Student-role row serializes number assignment between conversions for different
users. Existing unique indexes on Student user/profile/number and converted Student
link remain authoritative. Lock deadlocks retry at most three transaction attempts;
unique conflicts return safe 409 responses.

Only after Student insertion succeeds does the transaction change Guest to Student,
set converted status/link/time/version, write immutable conversion decision evidence
and `admission.student_converted` workflow audit, and revoke existing account tokens.
Any failure rolls back all of those writes, including token revocation. The same
username/password works on normal sign-in, which refreshes Student navigation.
Admission remains read-only for Student accounts. Result mutations on converted
cases are blocked for separate academic exception review; they never silently undo
conversion or demote/delete the Student.

Repeat confirmation verifies the existing Student user/profile link and conversion
evidence, then returns that Student without changing academic fields or duplicating
history. Existing academic identities without completed conversion evidence are
reconciliation conflicts, never automatically merged or overwritten.

### Registrar API and UI

Under `/api/admission/registrar/applicants/{id}`:

- `GET conversion`: eligibility, reason, current versions, academic choices and
  converted Student identity. Registrar only.
- `POST accept`: confirmed program/curriculum and application/result versions.
- `POST convert`: the same versions/academic IDs plus official student number,
  admission date and explicit confirmation.

Both mutations require existing password step-up and throttling. Admin, Professor,
Guest and Student are denied. The Registrar Applicants page → Inspect applicant
shows acceptance first; Convert to Student appears only after eligible acceptance.
Final confirmation identifies the applicant, program, curriculum, number and date.
Double submission is blocked. After success it displays Converted and the academic
identity, with instructions to use the existing account to sign in again.

### Validation and operational limits

Focused backend tests cover same-account/academic mapping, history/credential
preservation, token revocation, acceptance/result-version checks, duplicate retries,
number collisions, retakes, unpublished/failed results, course/curriculum validity,
existing-identity reconciliation, stale/non-current cases, authorization/step-up,
and full rollback after an injected audit failure. Frontend tests exercise eligibility
visibility, final confirmation/cancel, double-submit prevention and converted display.

Manual real MySQL/MariaDB endpoint race verification:

```sh
php backend/scripts/verify-admission-conversion.php --run-with-fixtures
```

It refuses production, uses synthetic fixtures only, races two confirmations for the
same applicant plus two different applicants competing for one official number,
checks Student/decision uniqueness and the losing Guest role, then removes only its
exact fixture IDs. All original schema/data fingerprints must match (auto-increment
counters naturally advance and are excluded). A private temporary ledger is retained
for recovery if the verifier is externally interrupted. Do not run against a busy
shared database because unrelated writes correctly fail the fingerprint comparison.

This completes the controlled initial-intake Applicant → Student lifecycle, not
academic enrollment. Production still requires an approved question bank and real
active course/curriculum configuration, official Registrar-issued numbers and review.
Transfer placement, automatic reconciliation, post-conversion academic exception
handling, offline backup and notification delivery remain outside this scope.

Final-phase validation: **208 backend tests passed, 1,613 assertions**; Pint passed;
all **10 frontend test files passed**; production Vite/PWA build and diff check passed.
The full run exposed an existing wall-clock-dependent exam test; freezing its clock
preserves the exact 120-minute assertion without changing exam behavior.
MariaDB endpoint races returned two HTTP 200 responses with the same Student ID
(one marked already-converted), and HTTP 200/409 for competing applicants using
one official number. Exactly two distinct Student records and two conversion
decisions were created across those races. The conflict account remained Guest.
All synthetic database fixtures were removed and original schema/data fingerprints
matched afterward.

A real Chromium walkthrough against a separate temporary SQLite database passed
Registrar program/curriculum acceptance, password step-up, final confirmation and
cancel, conversion display, old Guest token rejection, normal sign-in using the
same username/password and User ID, Student auth/navigation state, and read-only
Admission history. No browser page errors occurred. This browser fixture used a
private persistent file cache for step-up; no production credentials or database
records were used. Temporary test servers were stopped after validation.
