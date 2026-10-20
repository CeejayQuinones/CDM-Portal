# Event Phase 2A: Sub-events and Advanced Attendance

Phase 2A extends the existing Event records and responsibility model. A sub-event remains an `events` row with `parent_event_id`; nesting is limited to one level, its schedule must remain inside the parent schedule, and its audience is always inherited from the parent. `sort_order` controls display order. Removing a sub-event archives it so attendance and audit history remain available.

Each Event configures:

- `check_in_only` or `check_in_and_out`
- a late threshold in minutes
- an optional minimum participation duration for check-in/check-out Events
- allowed attendance methods: dynamic Event QR, static Event QR, static participant QR, dynamic participant QR, and manual attendance

Existing Events default to check-in only with the pre-Phase-2A methods enabled. Server time is authoritative for check-in, check-out, late status, and participation duration. Closing a session marks any record still waiting for check-out as incomplete.

## QR workflows

- **Dynamic Event QR:** assigned personnel display a short-lived rotating encrypted token. Eligible Students scan it. The Event, session, current token version, expiry, audience, and replay state are checked server-side.
- **Static Event QR:** assigned personnel display a signed session token. An eligible Student scan creates a pending verification only. Assigned personnel must confirm physical presence before attendance is written. A pending request is unique per Student, session, and action.
- **Participant QR:** the Student displays a signed static or short-lived dynamic identity token and authorized personnel scan it. Existing responsibility limits for Registered Moderators and Event Staff remain in force.
- **Manual:** authorized Event operators may record attendance and perform check-out. Existing step-up protection remains on corrections.

All write paths lock the attendance session and Student attendance row. The unique Event/Student attendance key remains the final duplicate guard. Check-in/check-out transitions, static verification reviews, session closure, and sub-event lifecycle changes write Event audit entries.

## API additions

- `DELETE /api/events/{event}` archives a sub-event with an optimistic `version` value.
- `POST /api/events/{event}/attendance/static-token` returns the staff-controlled static Event QR.
- `POST /api/events/{event}/attendance/{attendance}/checkout` records server-timed check-out.
- `GET /api/events/{event}/attendance/verifications` lists pending static Event QR confirmations.
- `POST /api/events/{event}/attendance/verifications/{verification}/{approve|reject}` reviews a pending confirmation.

The existing Event create/update, attendance scan, participant QR, operator scan, manual attendance, correction, reporting, and audience endpoints remain authoritative.
