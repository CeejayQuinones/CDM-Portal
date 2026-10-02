# Event QR Attendance

Phase 2 adds authenticated Student check-in to the existing Event foundation. It reuses portal Users, Students, finalized Enrollments, Courses, Sections, Sanctum authentication, client-platform policy, step-up authentication, and Event audiences.

## Schema and ownership

| Table | Ownership and purpose |
| --- | --- |
| `event_attendance_sessions` | One session per Event. Stores opener, open/close times, open/closed status, optimistic version, current token fingerprint/expiry, and rotation version. |
| `event_attendances` | One authoritative row per Event and Student, enforced by a unique constraint. Stores session, present/late/excused/absent state, check-in time, QR/manual source, recording actor, reason, and version. |
| `event_attendance_scans` | Append-only evidence for accepted and rejected attempts. Stores Student, Event/session where known, result, time, client platform, and SHA-256 token fingerprint. Raw QR tokens are never stored. |

No User, Student, Course, Section, or membership identities are duplicated. Attendance rows use `students.id`; authenticated identity always comes from the Sanctum User-to-Student relationship.

## Session and QR lifecycle

Registrar Staff and Admin can open attendance only while a published Event is currently in its start/end window. A repeated open request returns the existing open session. Closing is final for Phase 2, clears current QR validity, prevents further accepted scans, and is protected by a session version check.

The server produces an authenticated encrypted token with Event ID, session ID, issued time, expiry, rotation version, and a random 128-bit nonce. Laravel `Crypt` supplies authenticated encryption; no custom cryptography is used. Tokens live for **45 seconds**. The staff screen requests a new token every **30 seconds**. Generation locks the session and replaces the stored SHA-256 fingerprint/version, immediately invalidating the previous displayed QR. Only the current fingerprint is stored.

`qrcode` 1.5.4 renders the staff QR on a white, high-contrast surface. `html5-qrcode` 2.3.8 performs browser camera or selected-image decoding on the Student client. Event metadata decoded on the client is never trusted; the complete token goes to Laravel.

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

Registrar Staff and Admin can search the eligible list and create manual Present, Late, Excused, or Absent records with a required reason. Existing rows must use correction, require a reason and optimistic version, retain prior check-in evidence, change the source to manual, and write old/new status to audit. Registrar corrections use the portal's existing password step-up middleware; Admin follows the existing system-wide step-up policy.

Event audit actions are `attendance.session_opened`, `attendance.session_closed`, `attendance.recorded`, `attendance.manual_created`, and `attendance.corrected`. Audit writes share the attendance transaction. Safe metadata contains Event-owned record IDs, Student ID, state/source, and staff reason; QR secrets are excluded.

## Platform and endpoint security

Existing platform restrictions remain authoritative: Student scanning works on Web and Mobile and is denied on Desktop; Registrar management is Desktop-only; Admin management works on Desktop/Web; Professors and Guests cannot scan or manage attendance. Actor IDs, Student scan identity, source, status calculation, and timestamps are server-owned. Token generation, scanning, session changes, and manual writes have per-user/IP rate limits.

Camera permission denied, missing camera, scanner error, and unreadable image states render actionable messages. The camera stops after a successful scan and when the component unmounts. The UI retains loading, empty, error, duplicate, expired, ineligible, and success states in light/dark modes with responsive layouts and reduced-motion behavior.

## API

| Method | Route | Access |
| --- | --- | --- |
| GET | `/api/events/{event}/attendance` | Registrar/Admin live state and eligible roster. |
| POST | `/api/events/{event}/attendance/session` | Registrar/Admin open, idempotent while open. |
| POST | `/api/events/{event}/attendance/session/close` | Registrar/Admin close with version. |
| POST | `/api/events/{event}/attendance/token` | Registrar/Admin rotate short-lived token. |
| POST | `/api/events/{event}/attendance/scan` | Student self check-in; identity comes from authentication. |
| POST | `/api/events/{event}/attendance/manual` | Registrar/Admin manual creation with reason. |
| PATCH | `/api/events/{event}/attendance/{attendance}` | Registrar/Admin correction with reason/version and existing step-up policy. |

Phase 2 creates no bulk attendance seed data.

**Phase 3 remains:** reports, exports, and optional sub-events.
