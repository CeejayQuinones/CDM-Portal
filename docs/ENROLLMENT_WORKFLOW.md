# Enrollment Phase 2: core workflow

Phase 2 adds Student enrollment applications and staff review. **Approved is not
academically enrolled.** Existing `enrollments`, `enrollment_subjects`, sections,
subjects and schedules are not written by this workflow.

## Admission handoff and identity

Admission converts the accepted applicant into a Student using the existing User
and UserProfile, accepted Course, Curriculum and official Student number. The
Student signs in normally after conversion and opens Enrollment. Enrollment
reuses the foundation eligibility service and checks the persisted conversion
link and `student_converted` decision. It does not recalculate entrance results,
copy exam data, reset credentials, change roles or create another identity.
Valid legacy Students without Admission applications are still supported.

The current Student profile, program, curriculum, number and year level are shown
automatically. Application creation and submission snapshot only the existing
course/curriculum foreign keys and year level. No names or credentials are copied.
Academic status restrictions from Phase 1 remain: choosing Returnee does not
reactivate a Student on leave. The Registrar must reconcile academic eligibility
through the existing authorized academic workflow first.

## Additive schema

Migration: `2026_09_27_000001_create_enrollment_workflow_tables.php`.

| Table | Purpose and protection |
| --- | --- |
| enrollment_periods | Existing academic year + semester; one unique period per term; opening/closing instants, enabled flag, optimistic version, document requirements, creator/updater |
| enrollment_applications | Existing Student and period/term; course/curriculum/year snapshot; classification, status, version, submission/review timestamps, reviewer and notes; unique Student + academic year + semester |
| enrollment_documents | Required document type with either an existing StudentDocument link or a private Laravel file path; unique application + document type |
| enrollment_workflow_events | Append-only event UUID, actor/role, subject, action, safe version/status metadata and timestamp |

Foreign keys restrict referenced record deletion. Existing academic tables and
Admission tables are unchanged. The period/term tuple is assigned by the service,
never supplied as application fields; the immutable period term is checked during
period edits. Application term uniqueness is enforced in the database, including
cancelled/rejected applications. There is no automatic retry application or
resubmission transition in this phase.

Before deployment, review the additive migration and run:

```sh
cd backend
php artisan migrate --pretend
php artisan migrate
```

No seeders or fake production periods are installed. A staff member must configure
a real period using actual academic years/semesters. Do not use destructive reset
commands. Production migrations remain a deployment operation. During implementation, the
preview was checked against MySQL and this single migration was applied to the
configured **local** portal database. No period, application or production-like
fixture data was seeded.

## Period rules

Both Admin and Registrar can create, inspect, edit and enable/disable periods.
An enabled period is upcoming before opening, open from opening (inclusive) to
closing (exclusive), and closed at/after closing. Disabling closes it immediately.
Dates are enforced using backend time; the UI converts locally entered dates to
ISO timestamps. There is no late-enrollment bypass.

One period per term is enforced both in service validation and a unique index.
Term identity cannot change on edit. Document requirements freeze after the first
application is created. Dates/enabled state can still change with the current
version. Student period selection chooses the single open active academic term,
otherwise the next upcoming window or latest closed period for display. Multiple
simultaneously open periods fail closed: staff must resolve the ambiguity. The
service does not arbitrarily select a term for a Student.

## Student flow and classifications

Enrollment landing → availability and reused identity → create draft → academic
information confirmation → classification → academic path → review/documents →
explicit submit confirmation → application status.

Drafts can be saved; submission is protected against double clicks. The Student
can inspect their own past applications and staff review notes. After submission,
editing is disabled. Identity corrections go through the Registrar.

- **Regular:** normal curriculum path; standard load proposal comes in Phase 3.
- **Irregular:** customized load requiring academic history review; subject
  selection comes in Phase 3.
- **Transferee:** requires credit evaluation before final subject assignment;
  the evaluation engine is deferred.
- **Returnee:** requires academic review/reactivation as appropriate before final
  subject assignment; the evaluation engine is deferred.

Classification is Enrollment application data, not an authentication role and
not an automatic change to `students.student_status`.

## Lifecycle and staff workflow

Allowed transitions:

```text
draft → submitted → under_review → approved
submitted / under_review → rejected
draft → cancelled
```

Save draft stays in draft. No other transitions are exposed. Review requires the
current version. Approval requires under_review; rejection requires a nonempty
reason. Important actions have confirmation dialogs. Approval never creates a
final academic enrollment. Cancelled/rejected applications remain in history and
reserve their Student/term identity; reopening is intentionally not inferred.

Both staff roles use the same Enrollment → Applications / Enrollment Periods
navigation and backend authorization. The applications table is paginated and
supports Student name/number, Course, classification, status and term filters,
plus newest/oldest order. Details show the academic snapshot, documents and notes.
Staff can start review, approve or reject with optimistic concurrency checks.

## Permissions and privacy

Every API requires Sanctum authentication. Every service reloads role/account
status; writes lock the acting User. Student endpoints permit only an active
Student's own application. Admin and Registrar share staff operations. Guests,
Professors, inactive and suspended accounts are denied. Staff do not acquire
Student self-service rights. Admission and other module permissions are unchanged.

Responses use private/no-store. Unexpected errors return a safe generic response.
File paths are hidden. Private documents require an authorized application read
and are returned as attachments with nosniff. No base64 documents or public file
URLs are stored or returned. Uploads accept PDF/JPEG/PNG up to 10 MB with server MIME
validation and rate limits. Mutation endpoints are throttled.

## Documents and reuse policy

The reference repository requests identity/academic evidence, but those demands
are not blindly made mandatory for every CDM Student. Staff configure the school's
approved `document_types` per classification on the period form. Empty selections
mean no additional requirements; no unapproved policy is invented. Regular and
Irregular commonly need none; Transferee transcript/credit evidence and Returnee
clearance/reactivation evidence can be selected by staff.

Admission currently has no independent upload collection. Existing
`student_documents` are reused when owned by this Student, matching the type,
verified, and backed by an existing private file. A profile avatar does not count
as a verified 2×2 document. Submission automatically links reusable evidence;
Students do not upload it again. Missing requirements may be uploaded into private
`storage/app/private/enrollment/{application_id}` and referenced from
`enrollment_documents`; the existing StudentDocument collection is not modified.

Documents are required at submission and rechecked before approval. A reused
StudentDocument remains a live reference; if its verification or file changes,
access/approval revalidation can fail. Immutable issued evidence/COR is Phase 3.
Staff review the supplied evidence as part of their decision; this phase does not
run credit evaluation or change Document Requests.

## Transactions, races and audit

Creation locks the actor, existing Student and selected period; it rechecks
academic readiness and period availability before inserting. The Student/term
unique key is the final duplicate guard. Mutations serialize on Student/period/
application locks and compare versions. Submission rechecks the active User,
academic links, Admission handoff, open period and existing final enrollment.
Academic configuration rows are held during submission. Mutation eligibility and
requirement-freeze reads use locking current reads so a MySQL repeatable-read
snapshot cannot hide changes committed while a request waited for its locks. Staff racing to decide
receive a conflict after the first valid state/version change.

A repeat submit with the original immediately previous version returns the same
submitted application without a second audit event. Other stale changes return
409 and require reload. Period changes and submissions share a period lock; the
submission eligibility check is the decision point for closing-time races.
Database transaction retries handle deadlock serialization failures.

Audit follows the existing Admission pattern in an Enrollment-owned event table,
with a dedicated narrow writer (the existing writer only accepts Admission
subjects/events). Events include draft_created, draft_saved, submitted,
review_started, approved, rejected, cancelled, document_attached, period_created
and period_updated. Audit failure rolls back the database mutation. Upload failure
also removes a newly stored file. Application history and Admission history remain.

## Validation and academic finalization

Backend feature tests cover real Admission conversion before application, identity
counts, eligibility, role/status/ownership boundaries, draft/classification,
submission replay, DB uniqueness, transitions, stale staff decisions, both staff
roles, periods, required document reuse/privacy/upload, and audit/file rollback.
Frontend mounted-component tests cover protected navigation, period states, the
wizard, classification/save/review/confirmation, statuses, staff filters/period
forms, and stale-account response isolation. Full validation results are recorded
in the implementation report. SQLite tests exercise unique constraints and stale
request interleavings; they do not simulate MySQL row-lock contention.

Phase 3 now completes actual subject enrollment, controlled subject loads,
sections, schedules, Professor rosters, Student timetables, and an academic COR.
Its rules and schema are documented in
[ENROLLMENT_ACADEMIC_FLOW.md](ENROLLMENT_ACADEMIC_FLOW.md). No credit-equivalence,
fee, payment, or signature subsystem was inferred from absent source data.

## Changed files

This phase changes 29 files (including this document):

- `CDM_Frontend/src/config/accessControl.js`
- `CDM_Frontend/src/modules/enrollment/EnrollmentDialog.vue`
- `CDM_Frontend/src/modules/enrollment/EnrollmentStaff.vue`
- `CDM_Frontend/src/modules/enrollment/EnrollmentStudent.vue`
- `CDM_Frontend/src/modules/enrollment/EnrollmentView.vue`
- `CDM_Frontend/src/modules/enrollment/enrollment.css`
- `CDM_Frontend/src/modules/enrollment/enrollment.js`
- `CDM_Frontend/src/router/index.js`
- `CDM_Frontend/test/enrollmentFoundation.test.js`
- `backend/app/Http/Controllers/Api/Enrollment/EnrollmentWorkflowController.php`
- `backend/app/Http/Middleware/EnrollmentBoundary.php`
- `backend/app/Models/Enrollment/EnrollmentApplication.php`
- `backend/app/Models/Enrollment/EnrollmentDocument.php`
- `backend/app/Models/Enrollment/EnrollmentPeriod.php`
- `backend/app/Models/Enrollment/EnrollmentWorkflowEvent.php`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/app/Services/Enrollment/DatabaseEnrollmentPeriods.php`
- `backend/app/Services/Enrollment/EnrollmentAccess.php`
- `backend/app/Services/Enrollment/EnrollmentAuditWriter.php`
- `backend/app/Services/Enrollment/EnrollmentEligibilityService.php`
- `backend/app/Services/Enrollment/EnrollmentWindow.php`
- `backend/app/Services/Enrollment/EnrollmentWorkflowService.php`
- `backend/bootstrap/app.php`
- `backend/database/migrations/2026_09_27_000001_create_enrollment_workflow_tables.php`
- `backend/routes/api.php`
- `backend/tests/Feature/EnrollmentFoundationTest.php`
- `backend/tests/Feature/EnrollmentWorkflowTest.php`
- `docs/ENROLLMENT_FOUNDATION.md`
- `docs/ENROLLMENT_WORKFLOW.md`
