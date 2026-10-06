# Event QR Attendance

Phase 2 adds authenticated Student check-in to the existing Event foundation. It reuses portal Users, Students, finalized Enrollments, Courses, Sections, Sanctum authentication, client-platform policy, step-up authentication, and Event audiences.

## Schema and ownership

| Table | Ownership and purpose |
| --- | --- |
| `event_attendance_sessions` | One session per Event. Stores opener, open/close times, open/closed status, optimistic version, current token fingerprint/expiry, and rotation version. |
| `event_attendances` | One authoritative row per Event and Student, enforced by a unique constraint. Stores session, state, check-in time, source, actor, reason, version, and Phase 3 attendance-time Course/year/Section snapshots. |
| `event_attendance_scans` | Append-only evidence for accepted and rejected attempts. Stores Student, Event/session where known, result, time, client platform, and SHA-256 token fingerprint. Raw QR tokens are never stored. |

No User, Student, Course, Section, or membership identities are duplicated. Attendance rows use `students.id`; authenticated identity always comes from the Sanctum User-to-Student relationship.

## Session and QR lifecycle

Coordinators and assigned Semi-Coordinators can open attendance only while a published Event is currently in its start/end window. A repeated open request returns the existing open session. Closing is final for Phase 2, clears current QR validity, prevents further accepted scans, and is protected by a session version check.

The server produces an authenticated encrypted token with Event ID, session ID, issued time, expiry, rotation version, and a random 128-bit nonce. Laravel `Crypt` supplies authenticated encryption; no custom cryptography is used. Tokens live for **45 seconds**. The staff screen requests a new token every **30 seconds**. Generation locks the session and replaces the stored SHA-256 fingerprint/version, immediately invalidating the previous displayed QR. Only the current fingerprint is stored.

`qrcode` 1.5.4 renders QR credentials on a white, high-contrast surface. `html5-qrcode` 2.3.8 performs Mobile camera or selected-image decoding. Event metadata decoded on the client is never trusted; the complete token goes to Laravel.

Three QR paths now coexist without changing the authoritative attendance row:

- **Student self-check-in:** the existing encrypted rotating Event/session QR is displayed from Desktop and scanned by the authenticated Student on Mobile. It expires after 45 seconds and rotates every 30 seconds.
- **Registered Moderator:** the Student shows a signed static participant QR for that Event/session. An assigned `registered_moderator` scans it on Mobile. The static credential remains bound to one Event, Student, and open session; it cannot be used in the dynamic endpoint or by another responsibility.
- **Requested Moderator / Class Mayor:** the Student shows a signed participant QR containing a nonce and 45-second expiry. An assigned `requested_moderator` scans it on Mobile. Static credentials are rejected by this workflow.

Both operator paths recheck the open session, current Event window, Event binding, Student identity, audience eligibility, assignment validity and scope, and the unique Event/Student constraint. Raw participant tokens are not stored. Scan evidence stores only a SHA-256 fingerprint plus safe workflow/operator metadata.

## Scan validation and attendance state

`POST /api/events/{event}/attendance/scan` requires `auth:sanctum`, `client.platform`, the Student role, an active User, and an existing Student identity. Laravel then checks authenticated decryption, Event/session binding, expiry, current rotation fingerprint/version, open session, published/current Event window, and audience eligibility.

Eligibility reuses `EventAudienceResolver`. Course targets use `students.course_id`. Year and Section targets use finalized `enrollments` with `enrolled` or `completed` status in the active Academic Year and Semester. Admission history is irrelevant, so continuing Students work normally.

The late rule is intentionally fixed and simple:

- **Present:** accepted through 15 minutes after `events.starts_at`.
- **Late:** accepted after that grace period while both Event and session remain open.
- **Excused/Absent:** staff-managed states; absent rows are not generated automatically.

A unique Event/Student constraint, transaction, session lock, and attendance lookup prevent duplicate rows. A repeated successful scan returns `Attendance already recorded` with the existing record. Expired, tampered, rotated, other-session, ineligible, inactive-Event, and closed-session attempts receive plain-language responses and separate scan evidence.

## Staff monitoring and manual fallback

The Event detail dialog includes a live Attendance panel. It shows the rotating QR, countdown, session status, Eligible/Present/Late/Not checked in totals, and a paginated eligible-Student table with course, authoritative current year/Section, check-in, status, and source. It polls every seven seconds while the session is open and stops on close or component unmount.

Coordinators and assigned Semi-Coordinators can search the eligible list and create manual Present, Late, Excused, or Absent records with a required reason. Active Event-scoped Registered Moderators, Event Staff, and Requested Moderators can view the Mobile operational roster and verify an unrecorded attendee manually for their assigned Event. Registered Moderator adds static participant scanning; Requested Moderator adds dynamic participant scanning; Event Staff remains the manual-verification role. None can open/close the session, rotate the display QR, correct existing records, manage the Event/personnel, or open reports.

Existing rows must use correction, require a reason and optimistic version, retain prior check-in evidence, change the source to manual, and write old/new status to audit. Corrections remain Coordinator-only and require the existing staff password step-up middleware.

Event audit actions are `attendance.session_opened`, `attendance.session_closed`, `attendance.recorded`, `attendance.operator_qr_recorded`, `attendance.manual_created`, and `attendance.corrected`. Audit writes share the attendance transaction. Safe metadata contains Event-owned record IDs, Student ID, state/source/workflow, and staff reason; QR secrets are excluded.

## Platform and endpoint security

Both platform layers are authoritative. Coordinator and Semi-Coordinator Event administration is Desktop-only. Registered Moderator, Requested Moderator, Event Staff, Professor attendee, and Student attendee operations are Mobile-only. Web receives promotion-safe Event data only. Actor IDs, Student self-scan identity, scanned participant identity, source, status calculation, and timestamps are server-owned. Token generation, scanning, session changes, and manual writes have per-user/IP rate limits.

Camera permission denied, missing camera, scanner error, and unreadable image states render actionable messages. The camera stops after a successful scan and when the component unmounts. The UI retains loading, empty, error, duplicate, expired, ineligible, and success states in light/dark modes with responsive layouts and reduced-motion behavior.

## API

| Method | Route | Access |
| --- | --- | --- |
| GET | `/api/events/{event}/attendance` | Coordinator or active assigned personnel; Event-scoped live state and eligible roster. |
| POST | `/api/events/{event}/attendance/session` | Coordinator or assigned Semi-Coordinator; idempotent while open. |
| POST | `/api/events/{event}/attendance/session/close` | Coordinator or assigned Semi-Coordinator close with version. |
| POST | `/api/events/{event}/attendance/token` | Coordinator or assigned Semi-Coordinator rotates the token. |
| POST | `/api/events/{event}/attendance/scan` | Student self check-in; identity comes from authentication. |
| POST | `/api/events/{event}/attendance/participant-qr` | Eligible Student obtains a static or short-lived dynamic participant QR on Mobile. |
| POST | `/api/events/{event}/attendance/operator-scan` | Assigned Registered Moderator uses static workflow; assigned Requested Moderator uses dynamic workflow; Mobile only. |
| POST | `/api/events/{event}/attendance/manual` | Coordinator or active assigned personnel; manual creation with reason. |
| PATCH | `/api/events/{event}/attendance/{attendance}` | Coordinator correction with reason/version and password step-up; Desktop only. |

Phase 2 creates no bulk attendance seed data. Phase 3 reporting and sub-event behavior is documented in [Event Reports and Sub-events](EVENT_REPORTS.md); sub-events reuse this exact QR implementation.
