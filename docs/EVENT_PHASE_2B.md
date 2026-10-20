# Event Phase 2B: Analytics, Reports, Certificates, and Notifications

Phase 2B extends the existing Event and sub-event model. Sub-events remain child rows in `events` through `parent_event_id`; no parallel activity model was introduced.

## Analytics

`EventReportService` is the calculation source for the report API, Vue report screen, CSV, XLSX, PDF, and attendance-based certificate checks.

- **Eligible** is the authoritative Event audience resolved by `EventAudienceResolver`.
- **Checked in/out** count distinct attendance rows with server timestamps. Scan-log rows never count as people.
- **Completed** requires `present` or `late` attendance and the existing Phase 2A completion state. Check-in-only attendance completes at check-in. Check-in/out attendance requires a valid check-out and `completion_status=completed`.
- **Attendance rate** is checked-in participants divided by eligible participants.
- **Completion rate** is completed participants divided by eligible participants.
- **Absent** is not finalized while the Event/session is open and is not assigned for a cancelled Event.
- **Average duration** uses persisted server-calculated participation minutes.

Parent reports preserve each child Event's independent summary and also show a distinct cross-child attendee count.

## Exports

Coordinator exports support CSV, XLSX, and PDF. Semi-Coordinators retain scoped report viewing and cannot export. XLSX contains Overview, Attendance, Sub-Events, and Participation sheets. CSV and XLSX prefix spreadsheet-control characters in user-controlled cells to prevent formula execution.

## Certificates

Coordinators configure certificates in the existing Manage Event detail. Settings can require direct child Events and an optional total participation duration. Eligibility is evaluated on the server after the parent Event ends and reuses `EventReportService` completion rules.

An issuance is unique and idempotent per Event/Student while active. Revocation preserves the original opaque verification identifier. A later eligible issue creates a new version and identifier. Public verification exposes only Valid/Revoked, participant name, Event title, and issue date.

Attendance correction calls certificate reconciliation. If the correction removes eligibility, the active certificate is revoked and both operations are audited.

## Notifications

Event notices use Laravel's existing notification table. Important Event changes, reminders, and certificate availability can also queue email through the configured mail and queue connections. Delivery happens after the Event transaction and errors are logged, so mail failure cannot roll back a successful Event action. Run `php artisan events:send-reminders` manually; the scheduler runs it hourly with duplicate prevention based on the stored notification.

## Deployment

Apply migrations in order:

1. `2026_10_19_000001_add_advanced_attendance_to_events.php`
2. `2026_10_19_000002_add_event_analytics_and_certificates.php`

Run a queue worker and the Laravel scheduler in deployed environments for queued Event email and reminders.
