# Admission staff structure

This change extends the existing Admission lifecycle and CDM Portal layout.
No new schema, migration, production seeder, credential change, commit or push.
Monitoring, Document Requests and unrelated academic workflows are unchanged.

## Navigation and authorization

| Role | Menu | Browser hash route |
| --- | --- | --- |
| Admin | Programs | #/admin/admissions/programs |
| Admin | Exams | #/admin/admissions/exams |
| Admin | Exam Questions | #/admin/admissions/questions |
| Registrar Staff | Applicants | #/registrar/admissions |
| Registrar Staff | Results | #/registrar/admissions/results |
| Registrar Staff | Admission History | #/registrar/admissions/history |

Review / Publish redirects to Results. Admission Cycles redirects to Exams;
cycle management remains inside “Manage Admission cycles”. Neither legacy route
appears in the sidebar. Existing backend configure/review role boundaries remain
authoritative. Guests and Students retain their own Admission routes. No new
role or separate frontend was introduced.

## Programs

The existing academic courses table remains authoritative. Admin can list,
search by name/code, add and edit course code/name, department, duration in years,
and active/inactive status. Only existing fields are accepted. Database
uniqueness prevents duplicate course codes. Academic updates use a row lock and
expected timestamp, advanced even for consecutive edits within one second.
Configuration writes use the existing Admission Admin mutex and transactional
audit writer.

Course has no description column. No field or migration was invented:
description, display duration, subjects, careers, recommendation weights and
recommendability remain in admission_program_settings. “Edit program” and
“Recommendation settings” use separate endpoints so their data is preserved.
Program editing does not create curriculum, Student or enrollment relationships.

## One exam configuration per cycle

The existing admission_cycles.exam_policy JSON and policy_version columns
store the single configuration. No new table is required. Admin edits through
PUT /api/admission/admin/exams/{cycleId} with the expected policy version.
Cycles without a policy retain the original defaults:

- General Entrance Exam, active
- 120 minutes, passing score 75%, at most two attempts
- Five general categories, 20 questions each, 100 total

Validation allows 1–240 minutes, integer passing score 1–100, one or two attempts,
and 1–100 questions in each of exactly the existing five categories. Total count
is derived server-side. Three attempts remain prohibited. Exam status is draft,
active or inactive, distinct from the cycle's application status.

The current cycle exam must be active and the shared bank must satisfy the
applicant's required counts before a new attempt starts. Existing active attempts
continue despite configuration changes. New first attempts snapshot the policy;
retakes inherit the person's first-attempt policy. This preserves the promised
duration, threshold, counts and attempt limit after Admin edits. Lifetime
attempt counting is unchanged.

Legacy snapshots retain original 120/75/2/20-per-topic semantics. Grading,
time spent, published outcomes and retake filters use the session policy,
never today's configuration. Deadlines and assigned snapshots remain immutable.
Save/submit validate exact assigned IDs and position bounds. Applicant screens
only changed configuration-dependent count/duration/attempt labels.

## Shared questions and readiness

Questions remain general, never associated with courses. The cycle filter
selects readiness requirements, not a separate bank. Filters include category,
status, difficulty and question-text search. Rows show difficulty, status, key,
updated date and edit/activate/deactivate/delete actions. Assigned questions
cannot be deleted; retiring preserves the assigned snapshot.

Readiness and assignment use the same active-bank query. Production excludes
DEV-MVP-prefixed questions. Editing cannot remove a development marker.
This change adds no dummy questions or production bank. Keys remain Admin-only.

## Results

One Results page offers All Results, Pending Review, Ready to Publish, Published
and Retake sections. Search, cycle, attempt, publication status and latest-only
filters are supported. Rows show immutable system score/pass status, official
result/publication, topic evidence, attempt and version.

The backend supplies allowed_actions and revalidates under existing transaction
and owner locks. The frontend consumes this projection. Inspect is read-only;
Approve/Publish are primary. Correct and Exceptional Pass appear only when
allowed. Batch approval/publication require every selected row to allow the
action. Confirmation, version conflicts, password step-up, second-failure
exceptions, no active-retake edits, immutable decisions and conversion
restrictions are preserved.

## Applicants and history

Applicants show application, exam, latest result, acceptance and conversion
states with search/status/cycle filters. Inspection includes attempt summaries,
latest outcome, and the existing acceptance/conversion panel with authoritative
eligibility and converted Student identity. This remains the only staff
conversion workflow.

History is a paginated newest-first timeline joining immutable identity and
workflow ledgers at read time. It includes application creation, exam/retake
start, submission/expiry, result decisions, acceptance and conversion, plus
configuration events. It shows date/time, actor name (or system),
applicant/subject, cycle where applicable, action and safe summary.
No historical rows are rewritten. Keys, full answers and private reasons are
absent from the timeline. The existing Registrar-only decisions response remains
available for compatibility; private reasons remain restricted to that role.

## Exact browser walkthrough

Use the existing portal origin and append the hash routes above.
Use local test accounts/data for mutating checks.

1. Sign in as Admin → Admission → Programs. Search a course code.
   Click Add program; enter a unique code, name, existing department, years and
   status; Save. Use Edit program to update it or set inactive. Open
   Recommendation settings separately; confirm academic fields remain intact
   after saving its description/weights.
2. Open Exams and select a cycle. If none exists, expand Manage Admission cycles,
   click New cycle, choose an existing academic year, enter dates and save.
   Opening/closing retains the existing confirmation.
3. Review title, status, minutes, passing score, attempts and five counts.
   Check total and READY/NOT READY, then Save exam configuration. Open a second
   tab, save a change, then save the stale tab: a reload must be required.
4. Open Exam Questions, choose cycle context and Search. Combine category,
   status, difficulty and text filters. Add an approved question, edit it,
   activate/deactivate it and check readiness. Delete only an unused question;
   deleting an assigned question must be refused.
5. Sign in as a local Guest applicant. Start only when enabled and ready.
   Save, refresh and resume. Check the server deadline and configured count.
   Submit after confirmation.
6. Sign in as Registrar Staff → Results → Pending Review. Search the applicant
   number, Inspect, Approve and Confirm. Open Ready to Publish, Publish and
   Confirm. Verify Published. Select eligible rows to test batch review.
7. A first published failure under a two-attempt policy appears in Retake.
   The applicant may take attempt two. An active retake blocks changes to the
   first result. Eligible second failures expose Exceptional Pass; an internal
   reason and existing password step-up are required. Published corrections
   also retain step-up.
8. Open Applicants, search/filter and Inspect applicant. Review attempts and
   acceptance/conversion state. For an eligible current passed/published result,
   use the existing Accept application and Convert to Student workflow with
   valid course/curriculum and official Student number; explicitly confirm.
   Ineligible applicants must not expose these actions.
9. Open Admission History. Verify readable creation, exam, review, publication,
   acceptance and conversion events as applicable. Keys, full answers and
   private reasons must not appear in the timeline.
10. Repeat staff-page viewing on desktop, tablet and mobile. Tables scroll
    within their containers; idle dialogs close with Escape and retain existing
    focus/reduced-motion behavior. Guest/Student/Professor accounts must not
    access staff endpoints or menu items.
11. Check legacy #/registrar/admissions/review and #/admin/admissions/cycles
    redirects under their authorized roles.

## Validation and limits

Backend coverage includes authoritative Program CRUD, duplicate/validation/RBAC
checks, policy versioning/readiness, changed and legacy policy history,
one-attempt finality, dynamic save bounds, retake inheritance, question filters,
production exclusion/marker protection, Results sections/actions, applicant
inspection and safe timeline. Existing lifecycle, corrections, exceptions,
step-up, atomic conversion and concurrency-related tests remain in the suite.

Mounted frontend tests cover menus/guards, redirects, Results tabs/confirmation,
server actions, academic/recommendation separation, exam policy saving, applicant
inspection and history. Chromium checked six staff pages at 1440, 768 and 390
pixels, including dialogs and page overflow (18 combinations).
These browser checks used mocked API data, not live-database conversion.

A read-only MySQL/MariaDB query verified policy JSON filtering/casting and legacy
defaults without reading or changing application records. This does not replace
a live-database staff acceptance walkthrough.

An approved active question bank is still required; none was generated here.
Descriptions remain Admission metadata. Attempts intentionally cannot exceed
two. No migration was needed or applied.

Final validation: backend 214 tests / 1,740 assertions passed; Pint passed;
frontend all 10 test files passed; production build passed; git diff --check
passed. No schema migration was added or applied. No commit or push performed.
