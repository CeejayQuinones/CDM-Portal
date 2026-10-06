# Event Reports and Sub-events

Phase 3 completes the Event module with staff reporting, streamed CSV export, Student self-status, stable attendance-time academic snapshots, and one-level sub-events. Reports read the existing Event, audience, attendance, Student, profile, Course, Section, and Enrollment records. No reporting identity table is introduced.

## Report ownership and calculations

The Event audience resolver remains the source of the current eligible population. Recorded attendance remains the single `event_attendances` row identified by the Event/Student unique constraint.

- **Present, Late, and Excused** count authoritative attendance rows in those states.
- **Absent / Not Checked In** is derived as current eligible Students minus Present, Late, and Excused, with a floor of zero. The report does not create absent rows.
- **Attendance rate** is `(Present + Late) / Eligible × 100`. Excused Students remain visible but do not count as attendance.
- An explicitly recorded `absent` row appears as Absent in the table and remains part of the derived absent population.

Eligibility is dynamic academic data. A Student who later stops matching the audience can still appear because historical attendance rows are always retained in the report. This can make recorded totals differ from the current eligible population; the report never deletes or rewrites historical evidence to hide that change.

## Historical academic context

New attendance records snapshot `course_id_at_attendance`, `year_level_at_attendance`, and `section_id_at_attendance`. These fields exist only for stable historical reporting and never replace current Student, Enrollment, Course, or Section ownership. QR and manual creation populate the snapshot from the Student and active finalized Enrollment.

Attendance created before the snapshot migration remains valid. If all snapshot values are null, the report labels its academic context **Historical context unavailable** instead of silently substituting the Student's current academic assignment. Not-recorded Students use current authoritative academic identity because they have no attendance-time record.

## Reports and filters

The staff Reports page supports Event search, managed status, date range, and audience filtering. Event detail reports provide server-side Student search, status, Course, year, Section, and sort filters with pagination. Sorting supports Student name, check-in time, and status. Recorded attendance breakdowns group by snapshot Course, year, and Section.

Student Event detail exposes only that authenticated Student's own status and check-in time. It never returns the report roster.

## Export

`GET /api/event-reports/{event}/export?format=csv` streams a generated CSV directly to the authorized client. The filename is created from the Event title and date. It contains Event details, generation actor/time, summary values, and the currently filtered attendance rows. It excludes QR tokens, fingerprints, session internals, scan metadata, internal database IDs, and permanent server-side files.

CSV uses PHP and Laravel's existing streamed response support, so no export dependency was added. XLSX was not selected because the project has no existing spreadsheet library. PDF was intentionally omitted because the project has no PDF renderer and adding a heavy rendering stack for an optional format was not justified.

## Authorization and audit

Full reports and exports require active Registrar Staff or Admin authorization in addition to Sanctum and `client.platform`. Registrar remains Desktop-only. Admin follows the existing Desktop/Web policy. Students, Professors, and Guests cannot access report endpoints.

Opening a report writes `report.generated`; exporting writes `report.exported`. Export audit metadata includes only format and safe server-validated filters. Report and export endpoints are read-only apart from immutable audit evidence, and exports are rate limited.

## Sub-events

`events.parent_event_id` supports exactly one hierarchy level. A parent can contain any number of sub-events. A sub-event must remain inside the parent's schedule, cannot itself become a parent, and remains an independently visible Event with its own lifecycle and optional attendance session.

An empty sub-event audience means it inherits its parent's audience. An explicit sub-event audience overrides inheritance. The existing audience resolver applies either source, and the existing QR session, token validation, duplicate protection, audit, and attendance tables operate on the sub-event's normal Event ID.

Parent reports show each direct sub-event's own Eligible/Present/Late/Excused/Absent summary and the number of distinct Students marked Present or Late in any sub-event. They do not infer a parent attendance status or apply percentage-based grading.

Sub-event lifecycle writes `subevent.created`, `subevent.updated`, and `subevent.cancelled` audit events.

## API

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/api/event-reports` | Paginated, filtered staff report index. |
| GET | `/api/event-reports/{event}` | Formal summary, breakdowns, filtered attendance rows, and sub-event aggregation. |
| GET | `/api/event-reports/{event}/export?format=csv` | Stream the server-filtered CSV. |
| GET | `/api/events/{event}` | Includes authenticated Student self-status; staff detail includes sub-events. |
| POST/PUT | `/api/events` / `/api/events/{event}` | Accepts a nullable parent and an empty audience for inherited sub-events. |

The Reports UI uses the shared Event theme tokens, formal tables, accessible dialog structure, horizontal table scrolling, explicit loading/empty/error states, dark-mode-compatible surfaces, and reduced-motion behavior.
