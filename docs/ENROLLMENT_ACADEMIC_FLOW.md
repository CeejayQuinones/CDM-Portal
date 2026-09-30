# Enrollment Phase 3: academic load, scheduling and finalization

Phase 3 turns an approved Enrollment application into the portal's existing
academic `enrollments` and `enrollment_subjects` records. It does not create a
second User, Student, Course, Curriculum, Subject, or Admission identity.

## Reused academic ownership

- `subjects` owns Curriculum, semester, year level, units, lecture/laboratory
  hours, active state, and the existing single prerequisite.
- `sections` owns Course, academic year, semester, year level, capacity, state,
  and optional adviser.
- `section_subjects` owns one scheduled meeting per Section and Subject, including
  Professor, day, start/end time, and room.
- `enrollments` remains the final Student + term + Section academic record.
- `enrollment_subjects` remains the final enrolled-subject and Professor link.

The additive Phase 3 migration adds optimistic versions and conflict indexes to
Sections/schedules, draft load storage, an application Section/final Enrollment
link, staff load-review/finalization timestamps, and Laravel's standard database
notifications table. It does not replace existing academic tables.

## Subject-load rules

Regular applications receive the complete active subject set for the Student's
existing Curriculum, semester, and year level. The server generates this set;
the Student cannot substitute subject IDs. An empty standard load is rejected.

Irregular applications may select active subjects from that same Curriculum and
semester. The server rejects empty/duplicate IDs, subjects outside the
Curriculum, already completed subjects, and a subject whose existing prerequisite
has no recorded passing completion. A passing completion means an existing
enrolled/completed academic Enrollment whose EnrollmentSubject is completed with
`Passed`. Units are summed from persisted Subjects. Student edits clear prior
staff review; staff must review the final selection.

Transferee and Returnee loads use the same validated Curriculum boundary and are
staff prepared. The current schema has no approved transfer-credit equivalence or
course-substitution model, so the system does not fabricate one. Staff may use
only the existing academic history and Curriculum subjects until such policy and
data are explicitly designed.

## Section reservation and capacity

Only Admin and Registrar Staff can assign a Section. It must be open and match
the application's Course, year level, academic year, and semester. Approved
applications with a Section and no linked final Enrollment count as reservations;
pending/enrolled academic records count as occupied seats. Assignment and
finalization lock the active academic-year parent row, application, and Section,
then recount seats. This serializes concurrent reservations even when the Section
initially has no reservations. Cancelled/rejected applications cease counting
because only approved applications reserve seats. A failed transaction restores
the previous reservation and creates no partial final record.

Section edits use a version. A referenced Section cannot change its term, or its
Course/year level after use, and capacity cannot fall below occupied plus reserved
seats. There is no destructive Section-delete endpoint.

## Scheduling and conflicts

Admin and Registrar Staff share Section and schedule management. A schedule must
use an active subject belonging to an active Curriculum for the Section Course
and semester, plus an active Professor account. The existing unique Section +
Subject rule means one meeting per subject in a Section.

For meetings on the same day and academic term, an overlap exists when:

```text
new_start < existing_end AND new_end > existing_start
```

An overlap is rejected when the Section, Professor, or normalized room matches.
Different days and exactly adjacent meetings are allowed. Start must precede end.
Every writer locks the academic-year parent before checking existing meetings,
which protects the empty-set concurrent-insert case. Versions reject stale edits.
A schedule referenced by a final Enrollment cannot be deleted or moved to a new
Section/Subject; Professor changes update active EnrollmentSubject assignments.

## Atomic finalization

Only Admin or Registrar Staff can explicitly finalize an approved application.
Inside one retryable transaction the service locks/revalidates the actor, owned
User, Student, active year/semester, application, Section, current capacity,
subject load, schedules, and active Professors. It rechecks academic readiness,
the application's Course/Curriculum/year snapshot, staff review, prerequisites,
and the existing unique Student + term guard.

The service then creates one existing `enrollments` row with status `enrolled`,
creates one `enrollment_subjects` row per validated Subject, links the application
to the final record, changes the application to `enrolled`, timestamps it, writes
the allowlisted audit event, and writes the Student notification. Any exception,
including audit failure, rolls all of this back. Repeating finalization for the
already linked application returns the same result without another academic row,
subject row, event, or notification. Database unique keys remain the last guard.

## Access and views

Students can read only their own applications, loads, academic records, schedule,
COR, and Enrollment notifications. The COR exists only for finalized enrolled or
completed records and uses the actual Student number, Course, term, Section,
Subjects, units, hours, and current Section schedule. It includes no invented
fees, payment status, signatures, or authority claims.

Professors can read only Sections they advise or teach, their assigned meetings,
and enrolled rosters for those Sections. They cannot manage periods,
applications, Sections, schedules, loads, approval, or finalization. Admin and
Registrar Staff have equal Enrollment staff access. Guest, inactive, suspended,
and unrelated Student/Professor identities fail closed.

Lists are paginated. Records support Student name/number, Course, Section, term,
classification, and status filters plus stable sorting. Relations used by rows
are eager loaded, option lists are bounded, and the migration indexes the term /
state and overlap fields used by capacity and conflict queries.

## Notifications and audit

Phase 3 uses the User model's existing Laravel `Notifiable` support and the
framework database notification channel. It sends load ready/updated, Section
assigned, final enrollment, workflow rejection, and finalized-Section schedule
change notices where those events occur. It does not use Monitoring's risk
notifications and does not add another notification service.

Allowlisted Enrollment audit actions cover subject preparation/update, Section
assignment, finalization, Section creation/update, and schedule
creation/update/deletion. Audit and the associated mutation share a transaction.

## Remaining limits

- The schema supports one prerequisite per Subject and one meeting per
  Section/Subject; compound prerequisites and multi-meeting laboratory patterns
  require an approved academic model change.
- There is no transfer-credit equivalence or substitution table, so Transferee
  automation is intentionally limited to recorded history and staff review.
- The COR is a live academic view rather than an immutable issued-document
  snapshot. Schedule changes appear in the current COR/schedule.
- No fee schedule, assessment, payment, signature, or official issuance policy
  exists in the current data model, so none is displayed or inferred.
