# Grading Phase 2 review and release

## Scope

Phase 2 turns the Phase 1 Professor draft into a controlled review and publication workflow. It implements server-side computation, submission snapshots, Registrar/Admin review, return and resubmission, approval, release scheduling, and publication. Student grade viewing, GWA, messages, and exports remain Phase 3 work.

## Computation and rounding

The server is authoritative. The client sends assessment scores, category weights, and the Midterm/Finals weighting; it never sends an accepted final grade.

For each Student, period, and category:

`category grade = (sum of earned scores / sum of possible scores) × category weight`

The period grade is the sum of its category grades. The final numeric grade is:

`final grade = (Midterm grade × Midterm weight) + (Finals grade × Finals weight)`

Every score, maximum, weight, intermediate weighted category result, period result, and final result is handled as integer hundredths. Division and the final weighted combination use deterministic half-up rounding. This avoids browser-specific floating-point results and preserves two-decimal snapshots.

Each sheet initially uses the reference-derived 40% Midterm and 60% Finals weighting. The Professor may configure these values while the sheet is Draft or Returned, and the server requires them to total 100%. The four Phase 1 category weights must exist for both periods and each period must total 100%.

The portal has no approved institutional grade-point or passing-remark scale. The official numeric grade remains authoritative. `grade_point` and `remarks` are stored as `null` in every submission snapshot and displayed as not configured. Phase 2 does not infer a pass/fail outcome.

Archived assessments are excluded deliberately. Every active assessment must have a valid score, from zero through its configured maximum, for every Student in the official class roster. A positively weighted category must have at least one active assessment. An empty roster, missing score, invalid score, missing category, invalid weight total, or incomplete grading window blocks submission.

## Lifecycle and submission

The lifecycle is:

`Draft or Returned → Submitted → Returned or Approved → Published`

Only the assigned Professor can submit a complete owned sheet. Both Midterm and Finals deadlines must have passed. The Professor supplies the current sheet version, and the service locks and revalidates the row inside a transaction. Submitted, Approved, and Published sheets reject score, assessment, category-weight, and final-weight changes.

Each submission creates an immutable, numbered `grade_submission_attempts` record plus one normalized `grade_submission_students` record per official roster member. The attempt captures the sheet version, Midterm/Finals weights, category weights, active assessment configuration, computed Student results and breakdowns, timestamp, actor, and SHA-256 checksum. The checksum is built from canonical key ordering and normalized two-decimal numeric values.

## Return, resubmission, and approval

Registrar Staff and Admin/System Admin review the latest immutable submission. Reject & Return requires a reason and records the reviewer and review time. A Returned sheet exposes the reason and becomes editable again even though the original term deadline has passed. Resubmission creates the next numbered attempt; it does not update or delete earlier attempts.

Approve is allowed only from Submitted. Before return or approval, the service verifies the stored snapshot checksum, recomputes the current official roster and grade data, and requires that result to match the latest submission. This detects changes outside the controlled Professor endpoints. Approval records `reviewed_by` and `reviewed_at`.

Approve, Reject & Return, and Release Now use the portal's existing password step-up middleware with the all-staff scope, so both Registrar Staff and Admin must reauthenticate. Review and release endpoints use Sanctum authentication, client-platform enforcement, and Registrar-or-Admin role middleware. Registrar Staff use Desktop. Admin follows the existing Web/Desktop policy. Professor management uses Web and is restricted to owned sheets. Student and Guest accounts are blocked.

## Release schedules and publication

`grade_release_schedules` stores the term, release datetime, status, actor fields, execution fields, and optimistic version. `grade_release_items` links selected Approved sheets to one schedule, with a unique sheet constraint preventing duplicate scheduling.

Schedule creation locks the selected sheets, verifies that all are Approved, belong to the selected term, and still match their snapshots. A scheduled release datetime can be edited before execution with its current version. Released schedules are immutable.

The `grading:release-due` command processes due schedules through the same transactional service as the staff Release Now action. Laravel schedules this command every minute with overlap prevention. Automatic execution refuses a future schedule. Release Now is an explicit, step-up-protected override of the future time.

Execution locks the schedule, release items, and sheets. Every sheet must still be Approved and pass snapshot validation before any status changes commit. It records Published, `published_by`, and `published_at`, then marks the schedule Released. A failure rolls back the whole release. Re-executing a Released schedule returns the existing result without publishing again or duplicating audit effects.

## Concurrency and audit

Grade sheets and release schedules use optimistic `version` checks for browser staleness and database row locks for transitions. Submission attempts have a unique `(grade_sheet_id, attempt_number)` constraint. Release items have a unique `grade_sheet_id` constraint. Transactions cover snapshot creation, review transitions, schedule creation/update, and release execution.

Phase 2 adds these audit actions:

- `grade_final_weights.updated`
- `grade_sheet.submitted`
- `grade_sheet.resubmitted`
- `grade_sheet.returned`
- `grade_sheet.approved`
- `grade_release.created`
- `grade_release.updated`
- `grade_sheet.published`
- `grade_release.executed`

Audit metadata records identifiers, lifecycle transitions, versions, attempt numbers, release schedule IDs, and the return reason where applicable. It does not store credentials.

## Phase 3 integration

Phase 3 is implemented in `GRADING_STUDENT_EXPERIENCE.md`. Student and Registrar history consumes the latest Published submission snapshot, grade concerns use participant-scoped conversations, and reports use CSV exports. Because no approved institutional scale exists, grade points and remarks remain null and GWA remains explicitly unavailable. Published correction remains outside the current workflow.
