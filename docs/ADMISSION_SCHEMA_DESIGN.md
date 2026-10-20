# Admission identity and schema design — Step 2

Status: proposed for approval, 2026-09-19. Design only; no migrations, production
models/controllers, routes, permissions, or business behavior changed in this step.

## 1. Recommendation and evidence

**Choose B, implemented with an Admission-owned `admission_applicants` table:**
reuse `users` for authentication and `user_profiles` for the person; create one
application record per user per admission cycle. Do not create an academic
`students` row or assign the Student role merely to let someone take an exam.

Recommended baseline: **12 new Admission-domain tables** across future increments.
Only `admission_cycles` and `admission_applicants` are candidates for the initial
schema implementation in Step 3. The remaining ten are a design inventory, not
permission to build the full workflow. Existing academic/auth tables are reused.

| Option | Assessment |
| --- | --- |
| A: independent applicants with their own credentials | Reject separate authentication and duplicated personal identity. A domain applicants table is appropriate only when linked to portal users. |
| B: users + applicant profile/application | Recommended. One login and personal profile survive admission; applications and attempts remain historical. |
| C: reuse students directly | Unsafe. Portal students already represent academic records and require course/curriculum/admission date; prematurely assigning the role also opens existing Student services. |
| D: separate person registry and multiple identity providers | Unnecessary for this increment; would expand portal-wide identity scope. |

Inspection basis: repository migrations, models, routes, services, and current
source operations guide, not production database contents. The working tree was
clean on `feature-admission-integration` at inspection. Live schema drift, real
identity duplicates, and actual course mappings still need a read-only preflight
before any future data import.

### Verified portal constraints

- `users` has unique `username`, required password, one `role_id`, and account
  status `pending / active / inactive / rejected / suspended`. Login is by username
  and only permits active users. The authenticated model is `App\Models\User`.
- `user_profiles.user_id` is unique; email is nullable and unique there, not on
  `users`. Public registration normalizes email, verifies it, and creates an active
  **Guest**, with a profile and no Student record.
- `students.user_id` and `students.user_profile_id` are each unique. Required
  academic fields include `course_id`, `curriculum_id`, `admission_date`, and
  `year_level` (default 1). `student_number` is nullable, unique, at most 20 chars.
- Academic `student_status` is `regular / irregular / graduated / transferred /
  dropped / leave_of_absence`. None means applicant, exam pass, or admission denial.
- `courses` is the academic program master, attached to `departments`;
  `curriculums` belongs to courses. Portal seed codes include BSIT, BSCS, BSBA, BSED.
- Existing notifications and status-change history are domain-specific
  (`risk_notifications`, `document_request_status_changes`), not general Admission
  storage. Their tables and behavior must stay unchanged.

### Verified source constraints and differences

- Source `Student` is an authenticatable applicant account. Registration creates
  it and allocates `student_number` immediately (`CDM-<year>-<sequence>`), before
  examination or acceptance. Its `admission_year` is a string such as `2026-2027`.
  This number is labeled `applicant_number` in result-import templates.
- Source `Admin` is a separate authenticatable model/table, with `super_admin`,
  `admissions_staff`, `exam_manager`, and `viewer` roles. Sanctum tokens may belong
  to either source model. These identities/IDs are not portal User identities.
- `ExamSessionService` supplies server deadlines, two attempts, question snapshots,
  randomized category/question order, and 20 questions from each of five topics.
  Source text earlier in OPERATIONS says exactly 20 available; its later
  randomization section and current code permit larger banks and select 20/topic.
- Results distinguish immutable system score from mutable official score and
  `pending / approved / published`. Published outcomes include PASSED, RETAKE,
  FAILED. Exceptional Registrar pass is a separate override with an internal reason.
  Corrections are version-checked, latest-attempt-only, and blocked during a retake.
- Recommendation evidence currently lives in `exam_results.career_interests` and
  `recommendation_payload`; `recommendations` is a one-per-source-student top-choice
  projection. A tie can delete that projection. Current recommendation routes do
  not themselves require publication; result existence is checked. Do not assume
  old README behavior or treat a recommended course as admission acceptance.
- There is **no source academic Student conversion transaction**: applicants were
  already called students. The conversion rule below is a portal recommendation.

## 2. Identity lifecycle and login ownership

`users` remains the sole account and token owner. `user_profiles` remains the
canonical current personal/contact profile. `admission_applicants` is a case file,
not another person or password table. One user may have historical applications in
multiple cycles, but only one open application at a time is recommended initially.

```text
Existing/new portal User + UserProfile (active Guest)
                   |
                   v
       Application draft --withdraw--> withdrawn
                   |
                submit
                   v
               submitted ----------- incomplete at cycle close --> expired
                   |
                review
                   v
             under_review ---- admission refusal ----> rejected
                   |
          latest published PASSED exam result
                   |
       Registrar admission decision: accepted
                   |
       Registrar FINAL CONFIRMATION (all checks pass)
                   v
       converted application --links--> academic Student
                   |                         |
          same user/profile IDs       course + curriculum
          Guest -> Student role       new student_number

Drafts can expire; submitted/reviewed applications may be withdrawn.
Exam flow is separate: active session -> finalized result -> approved -> published
                                        published RETAKE -> second session
A published FAILED result is not itself an application rejection or account ban.
```

- **Incomplete:** retain draft/submitted evidence and request completion. At the
  cycle deadline, mark an unconverted application expired through an explicit,
  audited process. Do not delete its user or disable login. Define retention before
  implementing any purge. Never silently terminate an active exam via case expiry.
- **Rejected:** retain the decision and application; keep account active/Guest so
  the person can read permitted results and reapply in a later cycle. Rejection is
  not `users.status = rejected`; account suspension is a separate security action.
- **Accepted:** admission decision, not enrollment. It does not grant Student
  privileges until final confirmation succeeds.
- **Converted:** preserve the application and its foreign keys permanently for the
  applicable retention period. Applicant/result IDs never become student IDs.
- **Reapplication:** new case in another cycle under the same user, never overwrite
  the old case. Proposed two-attempt allowance is per application/cycle; resetting
  across cycles differs from the source's lifetime-per-account count and needs
  explicit approval. Until approved, do not enable a cross-cycle attempt reset.
- **Existing Student:** may read their own linked Admission history; do not create
  an applicant automatically for every Student. New admission/readmission for
  already-enrolled users is out of initial scope and needs a separate policy.
- **Existing staff/professor account:** do not demote/replace its single role to
  apply. Staff-as-applicant/multiple-role identity is an unresolved extension.

### Access change required after approval

Step 1 placeholders currently authorize Student and block Guest. Real applicant
self-service must instead accept active Guest users for starting their own case,
and Guest/Student users for their own existing cases. Ownership and lifecycle checks
must gate actions server-side; a Guest role alone does not grant exam eligibility.
Adjust only Admission navigation/routes/policies in a later authorized step, plus
its tests. Keep all existing Student-only and staff-only modules unchanged. Do not
change Step 1 access in this design-only step.

### Numbers and imported credentials

- `applicant_number`: stable public application reference, unique globally;
  recommend `APP-<UUID>` (40 chars),
  generated before insertion with a unique constraint and collision retry. Keep the
  cycle as a separate field; do not use an unsafe `MAX+1` counter. It is an identifier, not a login secret or student number.
- `student_number`: official academic identifier assigned/reserved by Registrar
  at final confirmation, subject to the existing 20-character unique constraint.
  Seed example `26-00001` is evidence of format use, not a verified production
  allocation policy. No production allocator was found in the inspected services.
  Initially require a Registrar-supplied official number, not an invented sequence.
- Preserve source `student_number` as `legacy_applicant_number`, never insert it
  automatically into portal `students.student_number` or `users.username`.
- Existing portal users retain passwords, usernames and verified profile data.
  Do not auto-link on matching email/name, import source tokens, or transplant
  Google IDs/password-reset/OTP/trusted-device rows. Source Google-only accounts
  have no usable portal password; an approved account-claim/provisioning flow is
  required before import. Identity matches require proof and an explicit mapping.

## 3. Source-to-target mapping

Source paths below are relative to the reference repository; target names refer
to CDM Portal. Ownership names denote domain responsibility, not database users.

| Domain | Source table/model | Portal equivalent | Target: reuse / extend / create | Conflict | Important links and ownership |
| --- | --- | --- | --- | --- | --- |
| Applicants | `students` / Student (auth + applicant + exam summary) | users/profile provide identity; students is academic | Reuse identity; create `admission_applicants`, `admission_cycles` | HIGH | applicant.user_id -> users; cycle -> academic_years; converted_student_id -> students; Admission owns application |
| Users/auth | students, admins, personal_access_tokens; OTP/trusted-device/reset tables | users, user_profiles, roles, personal_access_tokens, registration_email_verifications | Reuse portal auth; reviewed identity mapping only | HIGH | tokenable remains User; never preserve source numeric user/admin IDs as target FKs; platform owns credentials |
| Questions | exam_questions / ExamQuestion | None | Create `admission_exam_questions` | MEDIUM | author/editor -> users; Admission question-bank capability |
| Sessions | exam_sessions / ExamSession; exam_session_questions | None | Create `admission_exam_sessions`, `admission_exam_session_questions` | HIGH | session -> applicant; assigned row -> session/question; snapshot and deadline owned by Admission |
| Answers | exam_answers / ExamAnswer; active answers JSON on sessions | None | Create `admission_exam_answers` against assigned snapshots | HIGH | one answer per assigned question; result reached through session; never attach to portal grades |
| Results | exam_results / ExamResult | None; grades/enrollments are unrelated | Create `admission_exam_results` | HIGH | unique session -> result; applicant ownership via session; official actors -> users |
| Recommendations | recommendations / Recommendation, exam_results recommendation evidence, students.recommended_program | courses only | Create `admission_recommendations`; versioned evidence per result | MEDIUM | result -> recommendation; top_course_id -> courses nullable for ties; Admission owns guidance, not course assignment |
| Courses/programs | courses / Course, catalog replacement migrations | courses, departments, curriculums | Reuse academic master; create `admission_program_settings` for Admission metadata | HIGH | settings.course_id unique -> courses; manually reconcile code/major/institute, never merge by source ID |
| Registrar decisions | result approval/publication/override columns + admin_activity_logs | No equivalent; registrar_staff is staff identity | Create `admission_decisions`, with current official projection on results | HIGH | applicant/result/course/curriculum and actor_user_id FKs; Registrar owns decisions |
| Notifications | portal_notifications / PortalNotification (also email outbox) | risk_notifications is Monitoring-only | Create `admission_notifications`; reuse queue infrastructure | MEDIUM | recipient_user_id -> users; applicant/result/version evidence; no risk_notifications reuse |
| Audit/activity | admin_activity_logs, student_activity_logs | document_request_status_changes; no general Admission audit | Create `admission_audit_events` | MEDIUM | actor -> users nullable for system; typed subject identifier supports UUID sessions; restricted access |
| Import previews | result_import_previews | None | Defer; not required for initial Admission release | LOW now, HIGH if imports enabled | Future preview persistence must bind uploader User, row versions, expiry and atomic commit; not included in 12-table baseline |

Retain audited external-ID mappings for any future import. No source IDs are
implicitly portal IDs. Application provenance columns below cover source applicant
identity; mappings for source admins/questions/results/courses require a separately
reviewed import manifest before migration. No data import is authorized here.

## 4. Roles and capability mapping

Portal has `Admin`, not a separate `System Admin` role constant. Treat System Admin
as the business name for the existing Admin role, not a new role. No new top-level
role, new auth guard, or separate admin SPA is proposed.

| Source role/capability | Recommended portal assignment | Proposed capability names and boundaries |
| --- | --- | --- |
| admissions_staff: applicant/result work | Registrar Staff | `admission.applicants.read`, `.review`; `admission.results.read`, `.approve`, `.publish`, `.correct`, `.override`; `admission.applicants.convert`; Admission history read |
| admissions_staff: source course editing | Admin | `admission.programs.manage`; deliberate divergence from source to match Step 1's configuration ownership; no blanket academic-master write permission |
| exam_manager | Admin | `admission.questions.manage`, limited to question bank; not automatically a result publisher |
| super_admin | Admin | `admission.audit.read`, `admission.operations.manage`, future capability administration; source broad bypass is not copied |
| viewer (additional source role found) | No automatic privileged mapping | Future `admission.read` grant only if needed; do not map viewer to full Registrar/Admin |
| source applicant Student | Portal Guest before conversion, Student afterward | `admission.self.*` with authenticated user ownership and state checks; never staff capabilities |

Initially implement capability checks as named policies/Gates mapped to existing
roles; no permissions tables needed. Admin technical powers do not imply academic
approval, publication, or conversion by default. If selected Admins must substitute
for Registrar, approve a specific capability grant mechanism later. Do not use the
existing broad registrar-or-admin middleware as the entire authorization policy.
Require fresh user role/account status on each Admission request; current generic
portal role middleware checks role but not active status. Add an Admission-specific
active-account policy later instead of changing other modules. Use the existing
step-up pattern for conversion, overrides and corrections. Record the acting User
and role at decision time; do not require an Admin to have a registrar_staff row.

## 5. Exact Applicant -> Student conversion rule

**Trigger: an authorized Registrar submits final confirmation for an accepted
application, and one transaction passes every precondition below.** Exam submission,
approval, publication, recommendation, or offer acceptance alone never converts.
“Accepted” here means institutional admission acceptance, not applicant acceptance
of an offer. Requiring applicant offer acceptance would be an additional policy.

Preconditions:

1. Application is accepted and unconverted; its cycle permits final confirmation.
   User is active Guest; the canonical profile belongs to that exact user. Profile
   identity/completeness and current verified contact evidence have been reviewed.
2. Latest attempt's result is published and its current official outcome is PASSED
   (including an explicitly authorized, published Registrar-pass override). No
   active retake, unresolved correction, or version mismatch exists. A first failed
   result permitting RETAKE and a final FAILED result cannot convert.
3. The accepted decision references that result and version. If publication was
   corrected since acceptance, acceptance must be reviewed again before conversion.
4. Registrar confirms the offered active academic course, an active curriculum
   whose `course_id` matches it, admission date, first-year level, and initial
   academic status. For this initial intake recommend year 1 / regular; transfers,
   special programs and credit placement require another policy.
5. A valid official student number is supplied/reserved and not assigned to another
   student. Required portal fields must be real; no dummy course, curriculum or date.

Transaction and repeat-request behavior:

- Lock User first, then application, relevant session/result/decision records in
  consistent order. Re-check result and application versions and course/curriculum
  eligibility within the transaction. Result correction/finalization must use the
  same locking protocol later; otherwise the confirmation race remains possible.
- If already converted, return the same Student only after verifying the conversion
  evidence and user/profile link. A retry must not allocate a new number, overwrite
  course data, or emit a second decision/notification.
- Normally insert Student with existing user/profile IDs and confirmed academics;
  update Guest role to Student, set application converted_student_id/status/time,
  append conversion decision and audit event, and enqueue the in-app/outbox record
  atomically. Any failure rolls back all database changes.
- A pre-existing Student with no completed conversion evidence is a reconciliation
  conflict, not an automatic link or overwrite. Allow explicit Registrar linking
  only after identity/number/course/curriculum checks and an audited reconciliation
  decision. Unrelated email/number matches never justify account merging.
- Enforce existing unique user/profile/student-number constraints and application
  conversion uniqueness. Parallel confirmations for different cases of one user
  serialize on User. Close competing open cases with an explicit recorded decision;
  do not mutate already-converted or historical cases.
- Keep username/password/User ID unchanged. Recommend revoking that user's bearer
  tokens on the privilege transition and requiring normal sign-in to refresh the
  role; do not carry privileged authorization in stale frontend state. This is a
  proposed conversion behavior, not a global auth change.
- Do not create enrollment, grades, a section, document requests, or Monitoring
  records. Being an academic Student is not the same as being enrolled.
- Corrections after conversion retain the Student and trigger a Registrar exception
  review. Never auto-delete/demote an enrolled Student or silently reverse academics.

| Conversion field | Action |
| --- | --- |
| users.id, username, password; user_profiles.id and personal data | Reuse and link; no duplicated profile or password reset |
| students.user_id / user_profile_id | Link the verified existing pair |
| students.course_id / curriculum_id | Copy Registrar-confirmed IDs after relational validation, never recommendation text |
| student_number / admission_date / year_level / student_status | Assign confirmed academic values; require number even though existing DB permits NULL |
| applicant.converted_student_id / converted_at | Link once and retain permanently within retention policy |
| users.role_id | Guest -> existing Student role in the same transaction; users.status stays active |
| System scores, answers, recommendations, internal remarks, source account status, legacy applicant number | Do NOT copy to students or profile; retain under Admission |
| Application personal snapshot | Retain as historical evidence, never overwrite newer canonical profile data automatically |

## 6. Proposed target tables (12 total)

### Common conventions and invariants

These are logical definitions, not migration code. IDs are unsigned big integers
unless stated otherwise; User/course/curriculum FKs match existing types. All
mutable tables have UTC `created_at` and `updated_at`; nullable transition times are
listed per table. Statuses are bounded strings validated by policy and DB CHECKs
where supported; do not repurpose portal enums. All listed FKs are indexed.

Default FK deletion action is **RESTRICT**, including existing users/profiles,
academic records and historical actors. No cascading deletion of evidence. New
applicant-user FKs consequently block deletion of a user with Admission history;
this is intentional and requires an approved retention/anonymization process.
Do not promise physical deletion of a profile while retaining copied PII snapshots.
**No table requires Laravel soft deletes**: use explicit lifecycle/deactivation
states and append-only evidence. A legal/retention purge is a separate, reviewed
operation, not an ordinary application delete. No retention interval is invented.

Cross-table checks that ordinary FKs cannot express (matching user/profile,
course/curriculum, result/application, one active session, cross-cycle open case)
must be rechecked in locked transactions and tested on the eventual database engine.
Use DB uniqueness for identities and attempts; don't rely on validation alone.

### 1. `admission_cycles`

- Purpose: separate intake/attempt scope from academic enrollment; do not add open
  application dates to academic_years.
- Columns: id, unique `code` (max 16), `name`, `academic_year_id` -> academic_years,
  status, `opens_at`, `closes_at`, `confirmation_closes_at`, `policy_version`,
  `exam_policy` JSON (versioned duration/topics/count/attempt/threshold settings),
  `created_by_user_id`, `updated_by_user_id` -> users; common timestamps.
- Statuses: draft, open, closed, archived. Application submission ends at closes_at;
  final confirmations may continue through confirmation_closes_at. Code immutable
  after use; closing does not alter an active exam deadline.
- Indexes/constraints: UQ(code); IDX(academic_year_id, status), IDX(status, opens_at,
  closes_at); valid ordered dates and validated policy. No soft delete.

### 2. `admission_applicants`

- Purpose: one user's admission case for one cycle; not a credential store.
- Columns: id; `user_id` -> users; `cycle_id` -> admission_cycles;
  `applicant_number` varchar(40); status; `version` unsigned integer;
  nullable `preferred_course_id` -> courses; nullable `accepted_course_id` -> courses;
  nullable `accepted_curriculum_id` -> curriculums; nullable `converted_student_id`
  -> students; nullable `profile_snapshot` JSON plus `snapshot_version`;
  `verified_contact_email`, `contact_verified_at` (case-specific proof, not login);
  nullable `submitted_at`, `accepted_at`, `rejected_at`, `withdrawn_at`, `expired_at`,
  `converted_at`; nullable `source_system`, `source_applicant_id`,
  `legacy_applicant_number`; common timestamps.
- Statuses: draft, submitted, under_review, accepted, rejected, withdrawn, expired,
  converted. No exam status or account-suspension status here. Snapshot captured on
  submission; later corrections are audited/versioned rather than silent overwrite.
- Constraints: UQ(applicant_number), UQ(user_id, cycle_id), UQ(converted_student_id),
  UQ(source_system, source_applicant_id) when supplied together. A source applicant
  initially maps to one imported case; future cases omit that provenance pair.
  Converted status requires the Student link and timestamp, and vice versa.
- Indexes: (cycle_id, status, submitted_at), (user_id, created_at),
  (legacy_applicant_number). user_id is the canonical profile lookup; no duplicate
  profile FK needed. Enforce one open case across cycles under the User lock.
  Case verification must match current profile email at conversion; a changed email
  needs new verification. Historical contact evidence is not a second unique email.

### 3. `admission_program_settings`

- Purpose: Admission presentation/recommendation metadata on the existing academic
  program, not a second courses catalog.
- Columns: id, `course_id` -> courses, status, `is_recommendable`, description,
  `program_type` (degree/certificate), duration display text, nullable tuition
  display value/currency, subjects/career_paths JSON, image reference,
  recommendation_profile JSON, display_order, version, editor User FK; timestamps.
- Statuses: active, inactive. Also require the underlying course to be active.
- Constraints/indexes: UQ(course_id); IDX(status, is_recommendable, display_order).
  Settings cannot rename academic course codes, move departments, or assign a
  curriculum. Source institute text is not a department FK; reconcile explicitly.
  No separate applicant-program choice pivot until multi-choice intake is approved.

### 4. `admission_exam_questions`

- Purpose: controlled editable bank for future sessions.
- Columns: id, `question_code`, topic, question_text, option_a/b/c/d,
  correct_answer (A/B/C/D), difficulty (easy/medium/hard), status, version,
  created_by_user_id, updated_by_user_id -> users; timestamps.
- Statuses: draft, active, retired. Retire referenced questions; do not delete them.
- Constraints/indexes: UQ(question_code); IDX(status, topic). Source question_number
  is an ordering/display value, not trusted unique identity. Validate complete
  options/answer key and approved topic set. Keep keys off applicant serializers.

### 5. `admission_exam_sessions`

- Purpose: future authoritative timed attempt; applicant owns it before conversion.
- Columns: UUID id; applicant_id -> admission_applicants; attempt_number;
  status; bank_version/hash; immutable policy_snapshot JSON; revision; position;
  started_at; deadline_at; nullable finalized_at; timestamps.
- Statuses: active, finalized. An expired active session finalizes with an expiry
  cause on its result; abandoned sessions do not create a free new attempt.
- Constraints/indexes: UQ(applicant_id, attempt_number); IDX(status, deadline_at);
  attempt number starts at 1 and respects approved policy; deadline > start.
  One active session per applicant via parent locking (DB partial unique only if
  engine supports it). No result_id here, avoiding circular FKs; result owns link.
  No browser-storage timestamps trusted and no resetting deadline on resume.

### 6. `admission_exam_session_questions`

- Purpose: assigned-order evidence and immutable question/key snapshot, replacing
  both source session questions JSON and its membership pivot as the single truth.
- Columns: id; session_id -> admission_exam_sessions (UUID); question_id ->
  admission_exam_questions; position; topic; question_version; question_text;
  options JSON; correct_answer; created_at only.
- Constraints/indexes: UQ(session_id, position), UQ(session_id, question_id);
  IDX(question_id). No lifecycle status: immutable once assigned. Snapshot includes
  key and randomized order; bank edits never rewrite it. No updated_at or soft delete.

### 7. `admission_exam_answers`

- Purpose: one latest server-accepted answer per assigned question, frozen at
  finalization; avoid competing session JSON and normalized-answer authorities.
- Columns: id; session_question_id -> admission_exam_session_questions;
  selected_option nullable A/B/C/D; accepted_revision; nullable saved_at;
  nullable is_correct (not graded until finalization); timestamps.
- Constraints/indexes: UQ(session_question_id); ownership/attempt follow its parent
  session, so no conflicting applicant_id or result_id copy. Unanswered is NULL,
  not an artificial option. Lifecycle is derived from session; no status column.
  Server revision/deadline checks needed later; not implemented here.

### 8. `admission_exam_results`

- Purpose: one finalization per session; separate calculated evidence and official
  decision projection. This is not a grades row.
- Columns: id; session_id -> admission_exam_sessions; immutable raw_correct_count,
  question_count, system_percentage decimal(5,2), system_passed, category_scores
  and category_maximums JSON, time_spent_seconds, finalized_at, finalization_cause
  (submitted/deadline); official_score nullable integer 0..100; official_status;
  registrar_pass boolean; internal_reason nullable; version;
  nullable approved_by_user_id / published_by_user_id -> users; approved_at,
  published_at; timestamps. Applicant/attempt are derived from session.
- Official statuses: pending, approved, published. Outcome derived as PASSED,
  RETAKE, FAILED after publication using the versioned policy and approved override;
  no separate writable outcome that can contradict official fields.
- Constraints/indexes: UQ(session_id); IDX(official_status, published_at),
  IDX(finalized_at); valid score ranges; published requires approved score/actor/time
  or a complete authorized override decision. System evidence never overwritten.
  Corrections update only official projection/version and append decision evidence.
- Native results require a real session. Pre-session legacy results need a separate
  import/compatibility decision; never manufacture a trusted deadline or answer-key
  snapshot to satisfy this FK. The baseline is for new portal applications.

### 9. `admission_decisions`

- Purpose: immutable Registrar business decisions for results, applications and
  conversion, separate from generic activity events.
- Columns: id; applicant_id -> admission_applicants; nullable result_id ->
  admission_exam_results; actor_user_id -> users; action; unique operation_key UUID;
  application_version; nullable result_version; before/after JSON; internal_reason;
  nullable course_id -> courses, curriculum_id -> curriculums, student_id -> students;
  created_at only.
- Actions (not lifecycle statuses): application_accepted, application_rejected,
  application_closed, result_approved, result_published, registrar_pass,
  result_corrected, student_converted, existing_student_linked.
- Constraints/indexes: UQ(operation_key); IDX(applicant_id, created_at),
  IDX(result_id, created_at), IDX(actor_user_id, created_at). Enforce result belongs
  to applicant and course/curriculum match. Acceptance records its exact result
  version. Successful conversion is additionally unique through the applicant's
  state/link; a retry with another key still cannot convert twice. Append only;
  reversal/correction is a new decision, not editing history.

### 10. `admission_recommendations`

- Purpose: versioned per-result guidance and evidence; not an acceptance decision.
- Columns: id; result_id -> admission_exam_results; result_version;
  input_fingerprint; interests JSON; catalog_snapshot JSON; matcher_version;
  nullable model_name; generation_status; nullable ai_status; ranked_programs,
  evidence and explanations JSON; nullable top_course_id -> courses; generated_at;
  timestamps. Fingerprint includes result/official version and catalog/evidence.
- Statuses: pending, ready, unavailable; AI status not_requested, generated,
  unavailable. A tie permits ready with NULL top_course_id; no fabricated winner.
- Constraints/indexes: UQ(result_id, input_fingerprint); IDX(result_id, generated_at),
  IDX(generation_status). Saved completed generations are immutable; changed
  inputs/version make a new generation. Student-safe projection excludes internal
  override reasons, exact evidence/ranks and cached prose that disclose overridden
  scores. Publication visibility policy still requires approval (see decisions).

### 11. `admission_notifications`

- Purpose: Admission in-app notices and durable email outbox without using Monitoring.
- Columns: id; recipient_user_id -> users; applicant_id -> admission_applicants;
  nullable decision_id -> admission_decisions; event_key; type; title; safe message;
  route_name/route_params (allowlisted portal route, not arbitrary URL); read_at;
  email_status; email_attempts; email_retry_at; email_claimed_at; emailed_at;
  sanitized last_error; timestamps.
- Email statuses: pending, processing, sent, failed, skipped. In-app read state is
  independent. recipient must own the referenced application; status notifications
  remain accessible after the account becomes Student.
- Constraints/indexes: UQ(recipient_user_id, event_key) where event key includes
  decision/version; IDX(recipient_user_id, read_at, created_at);
  IDX(email_status, email_retry_at). Worker claim lease/recovery must prevent stuck
  processing rows. At-least-once email delivery is acceptable; database event
  deduplication cannot guarantee exactly-once SMTP delivery. Email failure never
  rolls back published results. Do not place internal results/keys in notices.

### 12. `admission_audit_events`

- Purpose: Admission activity/security history, including system actions and UUID
  subjects; business decisions remain in admission_decisions.
- Columns: id; unique event_uuid; nullable actor_user_id -> users; actor_kind
  (user/system); actor_role_snapshot; nullable applicant_id -> admission_applicants;
  subject_type allowlist; subject_id varchar(64); action; correlation_id;
  minimized before/after JSON; nullable IP/user-agent metadata; created_at only.
- Indexes/constraints: UQ(event_uuid); IDX(subject_type, subject_id, created_at),
  IDX(applicant_id, created_at), IDX(actor_user_id, created_at), IDX(correlation_id).
  Actor FK NULL only for system actions, not anonymous staff. Subject locator is
  deliberately polymorphic and lacks a DB FK; validate the allowed subject and
  write the event transactionally. Strong result/applicant FKs remain in decisions.
- No status/updated_at/soft delete: append only. Never log passwords, OTPs, bearer
  tokens or whole answer-key snapshots. Broad technical audit access does not imply
  permission to publish decisions or reveal internal evidence to applicants.

## 7. Field ownership and existing-table changes

| Data | Authority | Rules |
| --- | --- | --- |
| Login, password, role, account active/suspended state | Portal users/auth | Admission never creates a second principal; only approved conversion changes Guest role |
| Current name, DOB, email, contact, address | user_profiles | Verified existing profile wins; corrections reviewed; application keeps historical snapshot |
| Submitted facts, case number, lifecycle, preferred program | Admission applicant | Never put application rejection in users.status or academic student_status |
| Current academic program/code/department/curriculum | Academic courses/departments/curriculums | Admission references; source catalog cannot overwrite or reseed these |
| Admission program display/weights/eligibility metadata | admission_program_settings | A recommended/advertised course is not an academic placement |
| Timing, question assignment, scoring evidence | Admission examination domain | Server authoritative, snapshot immutable; no Monitoring grades/tables reused |
| Approval, override, publication, acceptance, conversion | Registrar decision domain | Versions, actor, reason and append-only history required |
| Student number, academic admission date/status/year level | Academic Student + Registrar confirmation | Independent identifiers and statuses; conversion does not enroll |
| Guidance, recommendation interests/evidence | Admission recommendation domain | Advisory only, versioned and filtered before self-service responses |
| In-app/email notices and audit | Admission operational domain | Minimal payload, recipient ownership and explicit retention |

**No existing-table column changes are required by this baseline.** Conversion
would insert into existing students and update an existing user's role; it does not
change the students schema. Reuse the existing queue tables for delivery. Persistent
account-wide verified-email state or fine-grained grant storage may be separately
approved later; neither is smuggled into this design as an implemented extension.
Case contact proof is needed because portal profile email lacks a permanent
email_verified_at and verification challenge rows are not a durable account ledger.

## 8. Conflict report

Severity describes impact if mapped incorrectly, not proof of a current defect.

| Collision | Risk | Evidence / resolution |
| --- | --- | --- |
| Source students versus portal students and Student role | HIGH | Applicant-auth versus academic records. Premature reuse grants existing Student services and requires invented academics. Use Guest + independent case. |
| users and source students/admins | HIGH | Two source principals, one target User, required username/password, one role. Proof-based linking; never copy source IDs/credentials or silently demote staff. |
| personal_access_tokens | HIGH | Source polymorphic owners Student/Admin differ from User despite matching class strings/table names. Never import token hashes or rewrite tokenable IDs heuristically. |
| Email uniqueness | HIGH | Separate source admins/students can share email; portal user_profiles has one unique nullable email. Normalize and report conflicts before import; never auto-merge accounts by email. |
| Student/applicant numbers | HIGH | Source registration number is not portal academic number; historical prefix/length/uniqueness differ. Separate numbers; Registrar confirms target number. |
| Courses/program catalog | HIGH | Source BSED-SCI, BSBA-HRM, TCP etc. do not map one-to-one to seeded BSED/BSBA; source text duration/institute differs from numeric years/department FK. Manual catalog/curriculum mapping; no replacement migrations. |
| Existing statuses | HIGH | Active account, accepted application, published result and regular Student are distinct. Four separate state machines; account-status imports require explicit review. |
| Step 1 Admission access | HIGH before real rollout | Guest currently blocked; Student used for placeholders. Approve Admission-only Guest/ownership policy and revise tests before enabling self-service. |
| Result corrections versus conversion | HIGH | Result can change after publication. Lock/version checks before conversion; post-conversion exception review, never automatic academic deletion. |
| Source historical results without sessions | HIGH for import | Older completed results may predate session migration. Required native session FK cannot justify fabricated timing/snapshots; import scope/legacy storage decision is a blocker for historical import. |
| Existing notification tables | MEDIUM | risk_notifications requires an academic student and risk context. Dedicated Admission notices to User avoid cross-domain coupling. |
| Existing audit history | MEDIUM | Document Request actors/subjects differ; source numeric subject_id cannot represent UUID sessions. Dedicated typed Admission audit; existing logs unchanged. |
| Role semantics | MEDIUM | Source super_admin bypass and source staff program editing differ from Step 1. Explicit capabilities; Admin technical access is not automatic academic authority. |
| Profile deletion/retention | MEDIUM | Existing user/profile cascades can erase academics; new restrict links deliberately prevent deletion with Admission history. Define retention/anonymization and assess admin deletion flows before release. |
| Email-verification evidence | MEDIUM | Challenge rows are reused; email lacks durable profile verified_at. Store case-specific proof and reverify changed contacts; don't import an untrusted verified flag. |
| New table names | LOW | No Admission tables in inspected portal migrations. Use admission_ prefix and verify deployed schema before applying anything. |
| Academic year versus admission cycle | LOW | Academic year exists; multiple intakes may share one year. Reference it through cycle, not a new duplicate school-year table. |

## 9. Future migration order (not executed)

1. Confirm policy decisions, DB engine/version and live-schema drift; back up and
   reconcile identity/course/number conflicts using read-only reports. No production
   PII was queried for this document. Freeze approved mappings before any import.
2. Step 3 candidate: admission_cycles, then admission_applicants, using existing
   users/courses/curriculums/students FKs. Keep real self-service behind an explicit
   later release decision. Do not seed fake academic records or real applications.
3. Later: admission_program_settings and admission_exam_questions.
4. Later: admission_exam_sessions -> admission_exam_session_questions ->
   admission_exam_answers -> admission_exam_results.
5. Later: admission_decisions -> admission_recommendations ->
   admission_notifications; admission_audit_events after applicants (deploy audit
   before enabling any case/result mutations, even if other tables are deferred).
6. Add approved constraints/indexes, data validation and policy checks; only then
   enable one workflow increment at a time. No conversion until decision/audit
   storage, verified policy, identity checks and concurrency tests are in place.
7. Separate approval for historical data import, claim/provisioning flows,
   spreadsheet preview persistence, and any capability-grant table. They are not
   implicitly included in the baseline or Step 3.

## 10. Rollback and retention considerations

- Before real data: reverse dependency order can remove empty new tables in an
  isolated environment. Check for data first; no `migrate:fresh` or blindly dropping
  tables containing applicant records.
- After intake begins: prefer disabling Admission entry points and forward fixes
  while preserving cases, sessions and audit. Stop intake/new starts before a
  rollback; active deadlines still need a controlled finalization strategy.
- After conversion: database rollback of Admission tables does **not** undo students
  or role changes. Retain conversion journal/links and take a consistent backup of
  both domains. Never restore only Admission data against later academic data.
- Reverse a mistaken conversion only via approved reconciliation after checking
  enrollment and other existing dependencies. Do not delete downstream records or
  automatically demote an account. Record a compensating decision.
- Existing users/passwords/academic tables and Monitoring/Document Request tables
  are never dropped or replaced. Queued notices must be paused/drained safely;
  external emails already delivered cannot be rolled back.
- Historical PII snapshots, internal reasons, IPs, outbox contents and account
  deletion need an institution-approved retention/anonymization schedule before
  production. Deactivation/soft deletion alone would not remove copied data.

## 11. Decisions requiring approval

| Decision | Recommended default | Must be resolved before |
| --- | --- | --- |
| Applicant identity and role | Guest User + canonical profile + one application/cycle; Student only on conversion | Step 3 schema/access implementation |
| Open cases/reapplications/attempt scope | One open case/user, unique user/cycle; allow future reapplication; two attempts/cycle only after explicit approval | Exam eligibility or cross-cycle reset |
| Conversion authority and acceptance meaning | Registrar institutional acceptance, then separate final confirmation; published latest PASSED including audited override; not automatic publication conversion | Conversion implementation |
| Official numbering and academic placement | Registrar-supplied official number initially; confirmed active course/curriculum, year 1/regular for first-year intake | Conversion implementation |
| Academic catalog alignment | Existing courses authoritative; manually reconcile majors/certificates/departments/curriculums; no fuzzy merging | Source program import or applicant program selection |
| Admin decision powers | Config/operations/audit only by default; exceptional academic authority via explicit capability policy | Result/admin workflow implementation |
| Applicant consent/offer acceptance and eligibility exceptions | No automatic offer acceptance, transfer/readmission or staff-as-applicant flow; approve separate requirements if needed | Intake/conversion requirements freeze |
| Recommendation visibility | Recommend published-result-only guidance with redaction; deliberate tightening versus current source, requires approval | Recommendation implementation |
| Imported accounts/history | Start with new portal intake; approve separate proof-based account claiming, admin mapping and pre-session result preservation strategy | Any source data import |
| Retention and account/contact proof | Keep rejected/expired accounts usable; explicit retention/anonymization; case-specific verified contact, reverify on change | Production rollout / deletion tooling |
| Conversion session handling | Revoke bearer tokens on role transition and ask for normal sign-in | Conversion implementation |

These decisions do not authorize schema changes in this step. No business policy
has been silently applied to existing Students or source applicants.

## 12. Recommended Step 3 and acceptance checks

After approving the identity/cycle choices, implement **only the identity schema
foundation**: migrations for admission_cycles and admission_applicants, narrowly
scoped models/relationships if authorized, and integrity tests. No exam engine,
recommendations, result publication or conversion yet. Decide Admission-only Guest
access explicitly; preserve existing non-Admission guards. An audit table can remain
for the next increment provided no production case-mutation API is enabled yet.

Required later checks: unique user/cycle and number conflicts; profile ownership;
Guest denial from existing Student services; cross-applicant denial; conflicting
role handling; rejected/incomplete login continuity; deletion restriction; migration
up/down on disposable databases. Before conversion additionally test two concurrent
confirmations, correction races, repeat requests, mismatched curricula, duplicate
student numbers, transaction rollback and preserved academic history.

### Source references used

Portal references (relative links):

- [User/profile migrations](../backend/database/migrations/2026_07_14_115748_create_users_table.php), [profile](../backend/database/migrations/2026_07_14_125025_create_user_profiles_table.php), [Student](../backend/database/migrations/2026_07_15_025419_create_students_table.php), [year level](../backend/database/migrations/2026_08_03_000000_add_year_level_to_students_table.php).
- [Courses](../backend/database/migrations/2026_07_14_134453_create_courses_table.php), [curriculums](../backend/database/migrations/2026_07_14_144145_create_curriculums_table.php), [academic years](../backend/database/migrations/2026_07_14_140532_create_academic_years_table.php).
- [Guest registration](../backend/app/Services/GuestRegistrationService.php), [registration validation](../backend/app/Http/Requests/RegisterGuestRequest.php), [authentication](../backend/app/Services/AuthenticationService.php), [roles](../backend/app/Models/Role.php), [role middleware](../backend/app/Http/Middleware/EnsureRole.php), [routes](../backend/routes/api.php), [Step 1 access](../CDM_Frontend/src/config/accessControl.js).
- [Tokens](../backend/database/migrations/2026_07_28_121452_create_personal_access_tokens_table.php), [email challenges](../backend/database/migrations/2026_08_26_000000_create_registration_email_verifications_table.php), [risk notifications](../backend/database/migrations/2026_09_06_000000_create_risk_notifications_table.php), [Document Request history](../backend/database/migrations/2026_08_30_000000_create_document_request_status_changes_table.php), [student update service](../backend/app/Services/StudentService.php).

Reference repository: `F:\frm_Chrome\CAPSTONE 1\AMISSION\cdm-career-recommendation-system`,
read through `/mnt/f/frm_Chrome/CAPSTONE 1/AMISSION/cdm-career-recommendation-system`:

- `docs/OPERATIONS.md`, `backend/routes/api.php`, `backend/config/auth.php`.
- Migrations `2025_01_01_000001` through `000006`; `2026_08_22_000001` (admins/status);
  `2026_09_01_000001/000002` (catalog); `2026_09_03_000001/000003` (activity/bank);
  `2026_09_06_000001`, `2026_09_07_000001`, `2026_09_08_000001/000002`,
  `2026_09_09_000001` (official results, recommendations, overrides, sessions,
  notifications, imports and question linkage).
- Models Student, Admin, ExamQuestion, ExamSession, ExamResult; controllers
  AuthController, AdminManagementController, ResultController, ResultReviewController;
  services ExamSessionService and ResultPublication; EnsureAdmin and
  EnsureStudentActive middleware. Current routed behavior takes precedence over
  unused controller methods and historical setup documents.
