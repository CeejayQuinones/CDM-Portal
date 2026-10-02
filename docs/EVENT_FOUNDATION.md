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

Registrar Staff and Admin can see every Event. Students and Professors can read only published Events selected for them:

- all-Student and all-user targets include Students;
- all-Professor and all-user targets include Professors;
- Course targets use the Student's official `students.course_id`;
- Section and year-level targets use finalized `enrollments` with status `enrolled` or `completed` in the active Academic Year and active Semester;
- a continuing Student does not need Admission history;
- draft, cancelled, and archived Events are never exposed to Student or Professor readers.

An Event is returned once when several audience selectors match. Audience rules are evaluated server-side; the frontend is presentation only.

## Authorization and API

All routes require Sanctum authentication and the existing `client.platform` middleware. Active Registrar Staff and Admin may create, edit, publish, cancel, archive, list, inspect, and load form options. Active Student and Professor accounts have read-only list/detail access. Guest and inactive/suspended accounts are denied. Existing platform rules remain unchanged: Registrar Staff uses Desktop, Admin uses Desktop or Web, Student uses Web or Mobile, and Professor uses Web.

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

Writes run in transactions, and the audit row is part of the same transaction. Routes are throttled and the UI prevents repeated in-flight submissions.

## Frontend and deferred work

The existing `/event-attendance` placeholder is now a unified workspace. Staff receive list/calendar views, filters, Event form and lifecycle actions. Students and Professors receive Today, Upcoming, and Past groupings and read-only details. Attendance is implemented in Phase 2; Reports remains a disabled Phase 3 item. The workspace uses shared portal theme variables, dark-mode tokens, responsive layouts, semantic tables, dialog labels, and reduced-motion support.

The existing database notification implementation is synchronous and its current Enrollment use cases have small recipient sets. Broadcasting an all-Student Event to the local 10,000+ Student dataset through that path would make the request unsafe. Event notification fan-out is therefore explicitly deferred until a queued, chunked delivery worker and retry/observability contract are designed. No misleading partial notification behavior is included.

**PHASE 2: QR Attendance.** Implemented as documented in [Event QR Attendance](EVENT_QR_ATTENDANCE.md).

**PHASE 3: Reports / exports + optional Sub-events.** Add attendance reporting and export only after the Phase 2 evidence model is established; introduce sub-events only if a real workflow requires them.
