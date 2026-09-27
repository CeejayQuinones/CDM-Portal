# Enrollment foundation and Admission handoff

## Current status — Phase 2

The foundation below is retained as the Phase 1 design/validation record.
Phase 2 is now implemented; its authoritative behavior and schema are documented
in [ENROLLMENT_WORKFLOW.md](ENROLLMENT_WORKFLOW.md). The historical statements below
about no migrations, read-only endpoints and staff placeholders describe Phase 1,
not current functionality. Phase 2 uses an additive period/application/document/
event layer, enables Student drafts/submission, and gives Admin and Registrar the
same period management and application review workspace. Admission identity and
existing final academic enrollments remain unchanged.

The implemented lifecycle supersedes the broader initial proposal below:
`draft → submitted → under_review → approved`,
`submitted/under_review → rejected`, and `draft → cancelled`.
Approval does not mark a Student academically enrolled. Phase 3 alone will add
subjects, sections, scheduling and COR/finalization.

## Architecture decision (Step 1)

Reuse the existing academic tables. Add no migration or production data in this
step. Existing enrollments require section_id and support pending/enrolled/
cancelled/completed; they are not a suitable place for a section-less draft
application. Keep their semantics intact. A proposed Enrollment-owned application
layer below will precede the existing final academic enrollment in Step 2.
Classification belongs to that application, never to an auth role.

## Reference review

Reviewed https://github.com/ayumiswift16-ux/ENROLLMENT at commit
e886ebc84239cff58df869c4860845b250e3a9ac (read-only clone under /tmp).
The repository contains a React/Firebase application AND a Laravel backend
scaffold; neither architecture is copied.

| Concept/source | CDM adaptation |
| --- | --- |
| src/types.ts EnrollmentType; Enroll.tsx | Regular, Irregular, Transferee, Returnee domain classifications |
| Enroll.tsx period gate; Settings.tsx; SystemSettings | Explicit year+semester enrollment period, server-owned; no hardcoded production dates |
| Enroll.tsx; Steps.tsx; Records.tsx | Future draft, submission, validation, approval and enrollment |
| Records.tsx; Laravel EnrollmentController | Future staff search/filter by student/program/type/status; never public records access |
| DocumentUploads; Enroll.tsx upload controls | Existing student_documents/profile evidence reuse; uploads deferred |
| Section; Records.tsx assignment | Reuse CDM sections, defer assignment |
| Scheduling.tsx; ScheduleModal/TimetableGrid | Reuse section_subjects schedule fields; defer conflict and timetable UI |
| RegistrationForm in types.ts; registration rendering in Enroll.tsx | COR/assessment/fees/payments deferred |
| lib/notifications.ts; Laravel Notification | Future enrollment notifications, separate from Monitoring |
| examDate/examVenue in reference EnrollmentRecord and validateRecord | Explicitly excluded: entrance exams remain Admission-owned |
| Firebase, hardcoded access, duplicate identity fields, reset controls | Not ported; existing Sanctum, profiles, roles and academic identity remain authoritative |

## Ownership map

| Owner | Existing data / responsibility |
| --- | --- |
| Auth/profile | users, user_profiles, roles; credentials and active account role |
| Admission | admission_applicants, cycles, exam sessions/results, recommendations, acceptance decisions, atomic Student conversion and audit |
| Academic core | students, courses, curriculums, academic_years, semesters, subjects, professors, registrar_staff |
| Enrollment | Existing enrollments and enrollment_subjects; future period, application classification/workflow and document requirement links |
| Shared academic offering | Existing sections and section_subjects; later Enrollment assignment/scheduling uses these, not duplicates |
| Student records | student_documents, document_types, verification and existing file storage; no Document Requests changes |

Students already link unique user_id and user_profile_id, course_id,
curriculum_id, nullable unique student_number, admission_date, year_level and
student_status. Course has department/code/name/years/status. Curriculum has
course/code/name/effective_year/status. Subjects directly reference curriculum
and semester, so a duplicate curriculum_subjects table is unnecessary.
Semesters are reusable term labels, not dated terms: academic_year_id +
semester_id is the academic term identity.

## Admission -> Enrollment handoff

Guest -> Admission application -> entrance exam -> published passing result ->
program/curriculum acceptance -> atomic Student conversion -> normal sign-in as
Student -> Enrollment status available.

Conversion remains solely in AdmissionConversionService. Its transaction creates
one Student with the same users.id and user_profiles.id, accepted course and
curriculum, official number, year_level=1 and student_status=regular; changes
Guest to Student; marks converted_student_id/converted_at; records the decision;
and revokes old tokens. Enrollment neither calls conversion nor creates User,
Profile or Student. No second credentials or password reset.

Enrollment checks the persisted handoff, never recalculates exam scores or
replays acceptance rules. Admission-origin identities must have a converted
application linked to the same Student and a student_converted decision.
Valid legacy Students without Admission applications remain supported.
Current Student academic links are authoritative after any later authorized
academic change; historic Admission program data is not overwritten.

## Eligibility contract

Only an authenticated active Student may read their own endpoint; caller IDs
cannot select another Student. Guests, Professors, Admin and Registrar are not
granted student-only endpoint access. Existing staff Enrollment placeholder
navigation does not grant API review/configuration permissions.

Academic readiness requires:
- exactly the existing Student, with a matching owned UserProfile;
- a nonblank official number and valid admission date/year level;
- regular or irregular academic student_status;
- active Course and active Curriculum belonging to that Course, effective no
  later than the current year;
- completed handoff evidence if an Admission application exists.

Graduated, transferred, dropped and leave_of_absence Students are blocked pending
the appropriate academic review/reactivation. Returnee classification does not
bypass this restriction.

The period resolver contract selects an explicit enrollment window for the
Student: period ID, academic-year ID, semester ID, opening and closing instants.
The default provider returns none: no production date or term is fabricated.
A real provider later must reject ambiguous overlapping periods, honor program/
classification restrictions and return only the authorized current window.
The service additionally validates the active year/semester and window dates.

A same-term existing enrollment blocks a new one regardless of status: the
existing unique index includes cancelled/completed rows too. Future resubmission
must reuse/reopen the same application/record through explicit rules, never
bypass the key. Eligibility is advisory, not a reservation: a future writer must
lock Student/application, revalidate inside its transaction and rely on unique
constraints for concurrent races. There is no write endpoint in Step 1.

Response distinguishes academic_ready from eligible. A converted Student can be
academically ready while eligible=false / enrollment_period_unavailable.
No open provider means no one is told that production enrollment is open.
The status response reuses own current identity and shows safe existing academic
enrollment status; it does not turn a pending legacy record into a new workflow.

## Classifications and workflow

Regular: normal curriculum path.
Irregular: nonstandard load; subject/prerequisite review comes later.
Transferee: requires credit evaluation before final subject enrollment.
Returnee: returning after absence; academic reactivation/review required.

Enums define these values now. Persistence will be on the proposed Enrollment
application, not users.role_id and not an automatic overwrite of student_status.
No classification is guessed or persisted in this read-only foundation.

Proposed application transitions:
draft -> submitted -> under_review -> approved -> enrolled;
under_review -> rejected;
draft/submitted/under_review/approved -> cancelled;
rejected -> draft only for an explicitly authorized revision.
Enrolled and cancelled are terminal for normal application actions.
Approval does not create final enrollment until later section/subject checks pass.
These application states do not replace the existing academic enrollment states:
pending, enrolled, cancelled, completed. No transition mutation is implemented.

## Schema inventory and proposal

All listed new tables are DESIGN ONLY; none is created in Step 1.
Do not create student_enrollments: existing enrollments is the final record.

| Table | Purpose / keys and constraints | Status / ownership / deletion |
| --- | --- | --- |
| enrollments (existing) | PK id; student_id, section_id, academic_year_id, semester_id FKs; unique(student_id, academic_year_id, semester_id); FK indexes | pending/enrolled/cancelled/completed; Enrollment final academic record; Student deletion cascades, year/term/section restrict; unchanged |
| enrollment_subjects (existing) | PK id; enrollment_id, subject_id, nullable professor_id; unique(enrollment_id, subject_id); FK indexes | enrolled/dropped/completed, grade and remarks; enrollment cascade, subject restrict, professor set null; unchanged |
| enrollment_periods (proposed) | PK id; year_id represented as academic_year_id FK, semester_id FK, created/updated_by_user_id FKs; unique(year, semester) for one window initially; index(status, opens_at, closes_at); opening/closing timestamps | draft/open/closed/archived; Enrollment configuration; restrict term/actor deletion; archive rather than delete referenced periods |
| enrollment_applications (proposed) | PK id; student_id, period_id, academic_year_id, semester_id, course_id, curriculum_id FKs; nullable unique enrollment_id FK; unique(student_id, academic_year_id, semester_id); FK indexes plus(period_id,status), (classification,status); version counter | classification four values and application statuses above; Enrollment-owned; restrict identity/academic/period/final-enrollment deletion; service verifies period/term and program consistency |
| enrollment_document_requirements (proposed) | PK id; period_id, document_type_id FKs; classification; unique(period_id,classification,document_type_id); FK indexes; required flag | active/inactive; Enrollment requirement policy; restrict referenced types/periods, retire rows |
| enrollment_documents (proposed links) | PK id; application_id, requirement_id, student_document_id FKs; unique(application_id,requirement_id); reviewer_user_id nullable FK; indexes(review_status), FK indexes; verified version/hash later | pending/accepted/rejected/waived; Enrollment-owned links only, never copied files; restrict application/evidence/requirement deletion, reviewer set null; ensure evidence belongs to application's Student |
| enrollment_application_events (proposed) | PK id; unique event_uuid; application_id FK, actor_user_id nullable FK; version/action/time; index(application_id,created_at) | append-only workflow audit; restrict application deletion, actor set null; allowlisted safe metadata, no answers/credentials/files |

Period term consistency: the application must use the period's exact
academic_year_id/semester_id. Step 2 should choose either an enforced composite
FK to a matching unique period key or an equivalent transactional constraint;
document the actual database implementation before adding migrations.
One period per term is the initial design, with reopening/versioning rather than
duplicate windows. Program-specific windows remain a future approved decision.

Existing sections: PK id; course/year/semester/adviser FKs; unique(course, year,
semester, section_name); year level/capacity; open/closed. Academic references
restrict deletion, adviser set null.
Existing section_subjects: PK id; section/subject/professor FKs;
unique(section,subject); day/start_time/end_time/room; section cascade,
subject restrict, professor set null. No duplicate sections or schedules table
is proposed now. Multiple meetings/conflict detection require a later design.

No COR, assessment, fee or payment tables are proposed in this step: there is no
equivalent COR model found, but their design is explicitly deferred.

## Identity and document reuse

Read names, contact/email and profile information from user_profiles; number,
program and curriculum from students plus existing academics. Enrollment rows
must not duplicate these live identity fields.
The future application may snapshot course_id/curriculum_id/year level at
submission and policy/requirement version at approval. A future issued COR may
snapshot approved display names, subject units and term labels for historical
reproduction. No snapshots or files are written now.

The reference asks for Summary of Grades/Form 137, Good Moral, Birth Certificate
and 2x2 photo. CDM already has student_documents/document_types and a profile
photo field. Admission itself does not implement a document-upload collection;
do not assume its application proves possession of those files.
Later requirements should link a verified existing StudentDocument rather than
request re-upload; a profile avatar is not automatically an acceptable 2x2.
Ownership, document type, verification, freshness and later immutable evidence
version must be checked before reuse. This does not change Document Requests.

Provisional policies for approval in Step 2: continuing Regular/Irregular may
need no repeat identity files; Transferee needs transcript/credit evidence;
Returnee needs reactivation/clearance. Good Moral/birth/photo requirements must
be confirmed by the school, not invented as mandatory rules here.

## Boundaries, non-goals, and Step 2

No enrollment creation, uploads, credit evaluation, subject assignment, sections,
scheduling, COR, fees, payments, professor tools or notifications are implemented.
Staff remain placeholders with no new review/configuration permissions.

Recommended Step 2: approve the period/application/requirement design; implement
additive period and application storage, classification persistence, Student
draft/submission and Registrar validation with audit/version/transaction guards.
Run migrate --pretend before any approved additive migration. Preserve the
existing final enrollments and their unique Student+term key.

## Implemented routes and response

- GET /api/enrollment/status
- GET /api/enrollment/eligibility

Both use the existing authenticated request context and the same read-only
EnrollmentEligibilityService. There is no student-ID route or client-controlled
term selection. Unauthorized roles/inactive accounts receive 403; unauthenticated
requests receive 401. An active Student with incomplete academic data receives a
safe own-status response with academic_ready=false and eligible=false. Unexpected
errors return a generic 503. Responses are private/no-store.

Fields include academic_ready, eligible, reason, applications_enabled=false,
period (null until configured), own Student/profile/program/curriculum references,
four allowed classification values, and up to ten existing own enrollment
summaries. No private Admission decision reason, exam key or answer is returned.
Classifications/statuses are contracts only in this step; nothing is persisted.

Browser /enrollment now includes Students while preserving the existing staff
placeholder. /enrollment/status is Student-only. The same small landing/status
view displays eligibility and reused identity plus future-step placeholders.
Student navigation becomes visible through the existing Student role after normal
post-conversion sign-in. It does not imply an open period; the server checks the
academic record and period before reporting eligibility. A damaged Student
record can view its own reconciliation message, not create an enrollment.
Guest and Professor navigation/access remain excluded.

## Validation scope

Backend tests exercise actual Admission acceptance/conversion before status
lookup, valid legacy Students, active-role boundaries, record/profile/program/
curriculum prerequisites, academic status restrictions, incomplete conversion,
unconfigured/closed periods, existing same-term records and the real database
unique key. Read-only checks assert User/Profile/Student/enrollment/decision row
counts are unchanged.

Frontend tests use the real router guards and navigation store plus mounted
Enrollment components: Student routes, staff placeholder without API calls,
safe blocked states, identity reuse, no forms/writes, retry and stale-account
response isolation. Existing Admission tests remain in the full suite.

No production database writes, schema migration or destructive command was run.
Any test-only windows/academic fixtures exist only in isolated SQLite test data.

Final checks: php artisan test passed (223 tests, 2,064 assertions);
php vendor/bin/pint --test passed; npm test passed (11 test files);
npm run build passed; git diff --check passed. No commit or push.
