# Event Attendance Phase 1 foundation

Phase 1 provides event planning and audience visibility. It does not create attendance, QR, scanning, reporting, export, capacity, registration, cover-image, or sub-event records.

## Ownership and schema

The module reuses the existing `users`, roles, Students, Professors, Courses, Sections, Academic Years, Semesters, and finalized `enrollments` tables. It adds three normalized tables:

| Table | Purpose |
| --- | --- |
| `events` | Event details, normalized venue key, schedule, managed status, optimistic version, and creating/updating User. |
| `event_audiences` | One or more audience selectors for an Event: all Students, all Professors, all academic users, Course, year level, or Section. |
| `event_audit_events` | Immutable create/edit/publish/cancel/archive activity with actor, role, safe metadata, and timestamp. |

There is no attendance or QR schema in Phase 1. Events are archived instead of deleted. Audience rows cascade only when their owning Event is removed by a migration rollback; academic references use restrictive foreign keys.

## Status lifecycle

Managed statuses are `draft`, `published`, `cancelled`, and `archived`. A draft may be published, cancelled, or archived. A published event may be cancelled or archived. A cancelled event may be archived. Cancelled and archived events cannot be edited or restored.

`ongoing` and `completed` are presentation statuses derived from the current time for published events. Keeping those values derived avoids scheduled background writes and stale status data.

Publishing rejects any time overlap with another published Event at the same normalized venue. Dates must be valid and the end must follow the start. Updates and status transitions lock the Event and require its current `version`, so stale staff screens cannot overwrite newer changes.

## Audience resolution

Admin Coordinators can see every Event in the Desktop administration workspace. Professor Semi-Coordinators see only their assigned Event tree. On Mobile, Students and Professors can read only published Events selected for them:

- all-Student and all-user targets include Students;
- all-Professor and all-user targets include Professors;
- Course targets use the Student's official `students.course_id`;
- Section and year-level targets use finalized `enrollments` with status `enrolled` or `completed` in the active Academic Year and active Semester;
- a continuing Student does not need Admission history;
- draft, cancelled, and archived Events are never exposed to Student or Professor readers.

An Event is returned once when several audience selectors match. Audience rules are evaluated server-side; the frontend is presentation only.

## Authorization and API

Operational routes require Sanctum authentication, the global `client.platform` middleware, and purpose-specific `event.platform` middleware. Desktop administration is limited to Admin Coordinators and assigned Professor Semi-Coordinators. Mobile participation is limited to Professors and Students; assigned moderator tools also require the correct Event responsibility. Web uses the separate promotion-safe API and never receives operational payloads.

Authenticated Web users, including Guest accounts, may call `GET /api/event-promotions` and `GET /api/event-promotions/{event}`. These endpoints expose only current published top-level Event title, description, schedule, venue, public organizer/audience labels, presentation status, and optional image URL. They never serialize audience selectors, capabilities, personnel, attendance, QR material, reports, or audit data. Unauthenticated requests are denied because the schema has no explicit public-visibility flag.

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/api/events` | Role-scoped searchable/filterable list. |
| GET | `/api/events/options` | Staff-only active Course/open Section options. |
| GET | `/api/events/{event}` | Role-scoped details; staff also receive audit activity. |
| POST | `/api/events` | Staff draft creation or immediate publish. |
| PUT | `/api/events/{event}` | Staff edit with version check. |
| POST | `/api/events/{event}/publish` | Publish a current draft. |
| POST | `/api/events/{event}/cancel` | Cancel a draft or published Event. |
| POST | `/api/events/{event}/archive` | Archive an Event. |
| GET | `/api/events/{event}/personnel` | Coordinator assignment list and eligible-user search. |
| POST | `/api/events/{event}/personnel` | Assign or idempotently reuse an Event/User responsibility. |
| PUT | `/api/events/{event}/personnel/{assignment}` | Change responsibility or validity window. |
| POST | `/api/events/{event}/personnel/{assignment}/revoke` | Idempotently revoke Event authority. |

Writes run in transactions, and the audit row is part of the same transaction. Routes are throttled and the UI prevents repeated in-flight submissions.

## Frontend and deferred work

The `/event-attendance` route selects a build-specific surface. Web renders promotional cards and safe details only. Desktop renders Event administration for Coordinators and assigned Semi-Coordinators. Mobile renders eligible Event participation, own attendance, self-check-in, participant QR credentials, and assigned operational tools. Phase 2 attendance and Phase 3 reports/sub-events remain integrated.

The existing database notification implementation is synchronous and its current Enrollment use cases have small recipient sets. Broadcasting an all-Student Event to the local 10,000+ Student dataset through that path would make the request unsafe. Event notification fan-out is therefore explicitly deferred until a queued, chunked delivery worker and retry/observability contract are designed. No misleading partial notification behavior is included.

## Global roles and Event responsibilities

The portal's global `users.role_id` remains unchanged. Coordinator, Semi-Coordinator, Registered Moderator, Event Staff, and Requested Moderator/Class Mayor are Event responsibilities, not login roles.

- **Coordinator:** an active Admin with institution-wide Event authority on Desktop.
- **Semi-Coordinator (`semi_coordinator`):** an active Professor assigned to an Event. It uses Desktop and is limited to its assigned Event and direct sub-events. It cannot create top-level Events, change lifecycle state, manage personnel, export, correct attendance, or view private audit.
- **Registered Moderator (`registered_moderator`):** an active Professor used as the existing-account mapping for security/office operational personnel. It uses Mobile, scans static participant QR credentials, and is limited to its assigned Event.
- **Event Staff (`event_staff`):** retained as a distinct Mobile manual-verification and entrance-assistance responsibility. It can monitor and manually verify attendance, but it receives neither moderator QR workflow nor Event administration.
- **Requested Moderator (`requested_moderator`):** an active Student/Class Mayor. It uses Mobile, scans short-lived dynamic participant QR credentials, and remains globally Student.

The compatibility migration maps stored `moderator` values to `registered_moderator` and `class_mayor` values to `requested_moderator`. The model continues to understand legacy values during mixed-deployment windows.

The unique Event/User key prevents duplicate assignments. Reassignment reuses the row, optional start/end timestamps control validity, and immutable audit entries record assignment, change, and revocation. Parent assignments inherit into direct sub-events; child assignments do not grant access to the parent or unrelated Events.

| Capability | Coordinator | Semi-Coordinator | Registered Moderator | Event Staff | Requested Moderator | Normal Professor/Student |
| --- | --- | --- | --- | --- | --- | --- |
| Client | Desktop | Desktop | Mobile | Mobile | Mobile | Mobile operations / Web promotion |
| Create top-level Event | Yes | No | No | No | No | No |
| Edit Event details/audience | All Events | Assigned Event tree | No | No | No | No |
| Create direct sub-event | Yes | Assigned parent | No | No | No | No |
| Publish/cancel/archive | Yes | No | No | No | No | No |
| View/manage personnel | Yes | View assigned | No | No | No | No |
| Open/close session and rotate Event QR | Yes | Assigned Event | No | No | No | No |
| Monitor attendance | Yes | Assigned Event | Assigned Event | Assigned Event | Assigned Event | Own status only |
| Manual verification | Yes | Assigned Event | Yes | Yes | Yes | No |
| Participant QR workflow | N/A | N/A | Static scan | None | Dynamic scan | Show own QR when Student |
| Correct existing attendance | Yes | No | No | No | No | No |
| View reports | Yes | Assigned Event | No | No | No | No |
| Export CSV / view audit | Yes | No | No | No | No | No |
| Student self check-in | N/A | N/A | N/A | Student assignee when eligible | When eligible | Student attendee when eligible |

Every operational Event response supplies server-owned capability flags. The frontend uses those flags to hide unavailable actions, and controllers repeat the checks. Web receives a separate promotion serializer. Mobile moderation is assignment-scoped, and Desktop Semi-Coordinator access is assignment-scoped. Authenticated Guest accounts can read the promotion-safe API but have no operational Event access.

Coordinator personnel management exposes safe names and portal roles, not credentials or unrelated profile data. Assignment audit actions are `event.personnel.assigned`, `event.personnel.role_changed`, and `event.personnel.revoked`.

**PHASE 2: QR Attendance.** Implemented as documented in [Event QR Attendance](EVENT_QR_ATTENDANCE.md).

**PHASE 3: Reports / exports + optional Sub-events.** Implemented as documented in [Event Reports and Sub-events](EVENT_REPORTS.md). CSV is the supported export format; optional XLSX/PDF rendering is intentionally omitted because the project has no existing renderer.
