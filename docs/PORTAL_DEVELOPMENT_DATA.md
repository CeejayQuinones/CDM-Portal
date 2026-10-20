# Admission and Enrollment development data

`portal:dev-seed` is an explicit local/testing command. It refuses production,
does not run from the normal database seeder, uses `DEV-ENR` identities and a
`generated_data_records` ledger, and never replaces existing Course, Curriculum,
Institute, academic year, semester, or real Student records. Generated accounts
use `example.test` addresses and random unusable passwords. No email is sent.

Run it only against a local database with the reviewed Enrollment Phase 3
migration applied and an active academic year, semester, BSIT Course, and active
Course Curriculums:

```sh
cd backend
php artisan portal:dev-seed
```

The command chooses existing active Institutes/Courses and active Curriculums.
For each suitable Course, it creates missing A–D Sections for each valid year up
to Year 4 in the current active term, with capacity 40. Existing matching
Sections are reused and never renamed or overwritten. BSIT must have all 16
`BSIT-1A` through `BSIT-4D` combinations or the transaction fails.

The fixture first reads existing Students, preserving their User, Profile, Course,
Curriculum, year level and Student number. Every academically valid active Student
receives a current-term development application unless an application or final
Enrollment already exists for that term. Continuing Students do not require
Admission history. Existing converted identities must still pass the normal
Admission reconciliation checks. Existing identities are never marked as owned
by this command, so cleanup cannot delete them.

Students owned by either `school-demo-v1` or `school-demo-v2` are excluded from
reuse. This prevents the development Enrollment fixture from treating the old
10,000-row load-test population as current local Students.

At most eight synthetic Students are created for a database with no existing
population; with sufficient existing Students only one synthetic freshman is
needed. This freshman originates as a Guest with a linked Admission applicant,
a fully snapshotted 100-question dummy exam, published passing result, accepted
decision, recommendation, and conversion through the real Admission service.
The same User and Profile are reused. Four additional demo Guests show draft, in-progress,
published failed, and published passed but unconverted Admission cases. Questions use the portal's existing
`DEV-MVP-` development prefix, are plainly labeled dummy, and are excluded by
the existing production exam-bank policy. They are never a production question
bank.

Development applications reuse existing Students across all valid year levels; classifications rotate through
Regular, Irregular, Transferee, and Returnee. Statuses cover draft, submitted,
under review, approved, rejected, and enrolled. Up to eight finalized examples use actual
Subjects from the selected Curriculum and term, Section schedules, an active
demo Professor, and the existing final `enrollments` and
`enrollment_subjects` tables. If no suitable Subject exists, that application
remains approved; the command does not invent a production curriculum Subject.
Demo application states are fixture rows for UI testing, not a way to bypass
normal API workflows. Normal production workflows retain all validation.

Repeat runs reuse ledger-owned records and do not duplicate Students or
Sections. The command reports the actual Institutes/Courses and counts. The
staff Student Records list now uses paginated rows, eager loading, and compact
existence summaries; full Admission/Enrollment and academic history is loaded
only when a Student detail page opens. An absent converted Admission link is
shown as “No linked Admission record,” never as failed Admission. Without such
evidence, year level or Enrollment classification can describe the current
academic path but does not assert historical Admission outcome.

Clean up only the ledger-owned fixture rows with:

```sh
cd backend
php artisan portal:dev-seed --cleanup
```

Cleanup is transactional and refuses to proceed if a demo Student has external
documents, requests, or appointments, or a demo Section has a non-demo
Enrollment. Existing Courses, Curriculums, academic terms, Subjects, Sections
that predated the command, and all unrelated users remain. Review any newly
attached activity before cleaning up a demo account.

## Refreshing the old 48-Student fixture

```sh
cd backend
php artisan portal:dev-seed --refresh
```

Refresh removes only untouched ledger-owned synthetic Students and their owned
applications/Admission data, then runs the existing-first seed strategy. It keeps
all Sections (including BSIT-1A through BSIT-4D), their IDs, the Enrollment period,
and shared fixture infrastructure. It preserves synthetic Students with subsequent
workflow events or unowned references, reports each refusal, and continues cleanup
of independent untouched identities. Protected retained Students can make the
synthetic count exceed the normal target; their activity is never erased to meet a
count. Full `--cleanup` remains all-or-nothing and refuses protected activity.
Inbound foreign-key checks prevent cascades from deleting untracked related data.
Existing Students' fixture applications are ledger-owned; their identities are not.

Scheduling lists existing active Subjects from effective active Curriculums for
the Section's Course, year level and semester. Sections have no curriculum_id;
multiple qualifying Curriculums can contribute subjects. The save validation uses
the same constraints. No subject is invented to populate a dropdown. Empty lists
show an explicit configuration message, and the command reports missing Course /
year / term coverage. The repository's SubjectsSeeder contains only three BSIT
Year 1 subjects; there is no approved development subject generator for missing
BSBA/BSCS/BSED or higher-year content.

## Student academic source of truth

Student identity fields belong to `students`; Admission history belongs to the
Admission tables; an application belongs to `enrollment_applications`; finalized
academic status, term, and Section belong to `enrollments`; enrolled Subjects
belong to `enrollment_subjects`; and the timetable belongs to `section_subjects`.
Student Records ranks finalized Enrollments by an open Enrollment period, then an
active academic year and semester, then academic dates. It falls back to the most
recent academic term and labels that summary `latest`. It does not use the highest
Enrollment ID as a proxy for the current term. Course, Curriculum, year level, and
classification in that compact summary come from the linked final application
when present; legacy identity fields remain the fallback.

## One-time legacy large-dataset cleanup

Always review the read-only analysis first:

```sh
cd backend
php artisan portal:legacy-large-dataset-cleanup --dry-run
```

The command proves identities through the generated-data ledger plus exact
creation timestamps, and proves old child rows through their deterministic
3-document, 5-request, 1-location structure and fixed seeder markers. Admission,
Enrollment, login, settings, risk, or later document workflow activity protects
the identity. Confirmed fixture-only child rows may still be removed from a
protected identity. Ambiguous rows are retained. The command refuses production
and requires exactly one explicit mode.

After recording non-target fingerprints and reviewing the counts, execute with:

```sh
php artisan portal:legacy-large-dataset-cleanup --execute
```

Deletion is transactional and follows child-to-parent foreign-key order. A
second dry run reports zero removable identities and zero remaining confirmed
fixture child rows; protected identity ledger entries remain deliberately.
