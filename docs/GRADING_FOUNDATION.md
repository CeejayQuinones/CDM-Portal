# Grading Phase 1 foundation

## Scope and reference flow

Phase 1 adopts the useful workflow from the referenced `Kleindev19/Grading_System` project: staff configure a term's Midterm and Finals windows, a Professor opens an assigned class, the official roster is loaded, assessments are organized by period, and draft scores are saved. The reference repository was used only as a feature and flow reference. CDM Portal keeps its existing Sanctum authentication, client-platform policy, roles, academic identities, enrollment records, and shared visual system.

Submission, Registrar review, return, approval, release scheduling, and publication are implemented by Phase 2 and documented in `GRADING_REVIEW_RELEASE.md`. Published Student history, explicit GWA-unavailable behavior, grade messages, and CSV exports are implemented by Phase 3 and documented in `GRADING_STUDENT_EXPERIENCE.md`.

## Source of truth

| Grading fact | CDM Portal source |
| --- | --- |
| Professor identity | `users` → `professors` |
| Teaching assignment | `section_subjects.professor_id` |
| Subject and units | `section_subjects.subject_id` → `subjects` |
| Section and program | `section_subjects.section_id` → `sections.course_id` |
| Academic term | `sections.academic_year_id` and `sections.semester_id` |
| Roster | finalized `enrollments` plus matching active `enrollment_subjects` |
| Student identity | `enrollments.student_id` → `students` → `user_profiles` |

Admission is not a grading dependency. The roster does not use a cached Student section and never scans all Students in a Section.

## Data model

The old `grading_periods` and `grades` tables are retained because they model named final-grade periods and are already consumed by monitoring. Phase 1 adds these tables through one additive migration:

- `grade_period_schedules`: one Midterm/Finals window configuration per academic year and semester.
- `grade_sheets`: one draft sheet per real `section_subject` assignment, with the assigned Professor captured.
- `grade_category_weights`: configurable per-sheet/per-period category weights.
- `grade_assessments`: normalized period, label, category, maximum score, order, active/archive state, and version.
- `grade_scores`: one versioned score per assessment and `enrollment_subject`.
- `grade_audit_events`: immutable actor, action, grading entity, timestamp, and safe metadata.

Official academic foreign keys use restrictive deletes. Internal sheet children cascade only from a grade sheet; no path cascades into Users, Students, Enrollments, or academic configuration.

## Period and window rules

Staff use existing `academic_year_id` and `semester_id` values. Date validation requires each deadline to be at or after its opening datetime. Runtime states are `Upcoming`, `Open`, and `Closed`.

The application timezone is UTC. `opens_at` is inclusive and `deadline` is exclusive: an edit at the exact opening instant is allowed, while an edit at the exact deadline is rejected. An archived schedule is closed. Every assessment, category-weight, and score write rechecks the schedule on the backend.

## Grade sheets, assessments, and drafts

Opening an owned class creates or fetches its grade sheet inside a transaction. The unique `section_subject_id` constraint makes this idempotent and concurrency safe. New sheets receive configurable defaults based on the reference example for both Midterm and Finals: Quiz 15%, Activity 35%, Recitation 10%, and Major Exam 40%. These values are defaults, not permanent institutional policy; the API can replace them only when the four weights total 100%.

Assessments can be added and archived/restored. Archiving preserves scores. Numeric scores must be between zero and the assessment maximum. The server verifies Professor ownership, assessment/sheet identity, official roster membership, and the open grading window. Existing score updates and assessment updates require matching versions, returning HTTP 409 for stale screens.

## Access and platform rules

- Registrar Staff and Admin/System Admin can list, create, update, and archive grade-period schedules.
- Professors can list only their own teaching assignments and edit only their own open grade sheets.
- Students and Guests have no Phase 1 grading access.
- Every route uses `auth:sanctum`, `client.platform`, and the existing role middleware. Existing platform rules continue to require Registrar Desktop, allow Admin Desktop/Web, and allow Professor Web.

The frontend exposes `Grading / My Classes` to Professors and `Grading Management / Grade Periods` to Registrar Staff and Admin. It uses shared semantic theme variables, clear loading/error/empty/locked states, a responsive horizontally scrolling table, and sticky roster identity columns where space permits. Phase 2 adds the computed Final Grade preview and controlled review/release workflow.

## Audit actions

Phase 1 records `grading_period.created`, `grading_period.updated`, `grading_period.archived`, `grade_sheet.created`, `assessment.created`, `assessment.updated`, `assessment.archived`, `assessment.restored`, `grade_weights.updated`, `score.created`, and `score.updated`. Score metadata contains academic row identifiers and old/new numeric values; it does not contain credentials or unrelated personal data.

## Deferred work

Phase 2 adds grade-sheet submission, Registrar/Admin review with version-safe approval or return, server-side final-grade computation, release scheduling, and publication. Phase 3 consumes only Published snapshots for Student/staff history, adds participant-scoped grade messages and safe CSV exports, and explicitly leaves GWA unavailable until an institutional grading scale exists. No additional Grading phase is planned.
