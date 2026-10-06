# CDM Portal IT Test Accounts

This document records the roles and account purposes required for IT validation. Operational-account credentials belong in the validation team's approved secret channel or password manager. The only passwords recorded here are the fixed credentials for the dedicated local-development Event accounts listed below; they are not production credentials.

The local validation database was checked on 2026-10-03 and contains one active account for each required role: Registrar Staff, Admin, Student, Professor, and Guest. It also contains the required Student academic identity for the Student account. Password validity must be confirmed privately during the login test.

## Registrar Staff

Email/username: __________  
Role: Registrar Staff  
Platform: Electron desktop only  
Purpose: Dashboard, Admission, Enrollment, Student Management, Document Requests, Appointments, Physical Records, Monitoring, and settings  
Credential owner/contact: __________  
Reset completed/date: __________

## Admin

Email/username: __________  
Role: Admin/System Admin  
Platform: Web and Electron desktop  
Purpose: Admission, authorized Enrollment/system management, Events, Event Reports, settings, and cross-module platform verification  
Credential owner/contact: __________  
Reset completed/date: __________

## Student

Email/username: __________  
Role: Student  
Platform: Web and Capacitor mobile  
Purpose: Dashboard, own Enrollment, Document Requests, Event visibility, QR scanning, own attendance status, settings, and profile photo  
Student number: __________  
Current course/year/Section: __________  
Credential owner/contact: __________  
Reset completed/date: __________

## Professor

Email/username: __________  
Role: Professor  
Platform: Web and Mobile; Desktop only while assigned as an Event Semi-Coordinator  
Purpose: Dashboard, schedule, assigned class/roster surfaces, Web Event promotion, Mobile Event participation/assigned moderation, and negative staff-route tests  
Assigned Section/subject: __________  
Credential owner/contact: __________  
Reset completed/date: __________

## Guest/applicant

Email/username: __________  
Role: Guest  
Platform: Web only  
Purpose: Registration/login, own Admission application/exam/result history, and negative staff/academic-route tests  
Admission cycle/application: __________  
Credential owner/contact: __________  
Reset completed/date: __________

## Account preparation rules

1. Confirm the account is active and has exactly the expected role before distributing credentials.
2. Confirm Registrar desktop-only, Admin web/desktop, Student web/mobile, Professor web/mobile plus assignment-scoped Event Desktop, and Guest web-only behavior before workflow testing.
3. Use a separate Student that is eligible for the physical Event audience. Record its Student number above, but keep its password outside Git.
4. Use a separate ineligible Student only if the validation database intentionally provisions one. Do not alter a legitimate Student merely to force a negative case.
5. Restore any password or status changed for negative testing immediately after recording the result.
6. Never share bearer tokens, QR payloads, password hashes, verification codes, or database connection values.

## Local generation and reset references

The repository contains local seed definitions under `backend/database/seeders`. Do not copy general operational credentials into this document or an IT report. The dedicated Event fixture credentials below are documented explicitly for local manual testing, as required by that fixture.

- On a new, dedicated, empty local database, `php artisan db:seed` creates the baseline roles, accounts, profiles, personnel, academic reference data, document types, and physical-record seed data. Do not rerun this non-idempotent baseline command against the prepared validation database.
- `php artisan db:seed --class=AdminUserSeeder` is the existing local Admin create/reset seeder. Use it only with validation-owner approval because it changes the Admin credential to the development value defined in source. Transmit that credential privately and change it after validation.
- `php artisan portal:dev-seed` is the explicit local/testing Admission and Enrollment scenario generator. It is not needed when the five prepared accounts and required workflows already exist. If the validation lead authorizes it, remove only its tracked data afterward with `php artisan portal:dev-seed --cleanup`.
- Guest test accounts can be created through the normal public registration and verification flow using a validation-owned mailbox.
- Student, Professor, and Registrar password changes should use their normal authenticated password-change flow. If the current password is unavailable, use a controlled local-only operator reset procedure approved by the validation owner; do not add a default password to documentation or source.

## Pre-validation account check

| Check | Result | Notes |
| --- | --- | --- |
| Registrar account active and desktop login verified | ☐ Pass ☐ Fail | __________ |
| Admin account active and web/desktop login verified | ☐ Pass ☐ Fail | __________ |
| Student account active, academically linked, and web/mobile login verified | ☐ Pass ☐ Fail | __________ |
| Professor account active and web/mobile login verified | ☐ Pass ☐ Fail | __________ |
| Guest account active and web login/Admission access verified | ☐ Pass ☐ Fail | __________ |
| Passwords stored outside Git and shared only with validators | ☐ Pass ☐ Fail | __________ |
| Negative-test status/password changes restored | ☐ Pass ☐ Fail | __________ |

## Event development login accounts

**LOCAL DEVELOPMENT ONLY.** `EventRoleTestingSeeder` creates seven deterministic portal accounts with the same role-specific fixed-password convention used by the baseline account seeders. These credentials must never be used in production. The seeder refuses to run in production, owns the `event_*` usernames, resets only those dedicated development accounts, and does not reuse unrelated users.

| Persona | Username | Password | Global portal role | Event responsibility | Event client |
| --- | --- | --- | --- | --- | --- |
| Coordinator | `event_coordinator` | `Admin123!` | Admin | Coordinator through existing Admin authority | Desktop |
| Semi-Coordinator | `event_semicoordinator` | `Professor123!` | Professor | `semi_coordinator` | Desktop |
| Registered Moderator | `event_registered_moderator` | `Professor123!` | Professor | `registered_moderator` | Mobile |
| Event Staff | `event_staff` | `Professor123!` | Professor | `event_staff` manual verification | Mobile |
| Professor | `event_professor` | `Professor123!` | Professor | None; normal Professor/attendee | Mobile operations / Web promotion |
| Requested Moderator | `event_requested_moderator` | `Student123!` | Student | `requested_moderator` / Class Mayor | Mobile |
| Student | `event_student` | `Student123!` | Student | None; normal Student attendee | Mobile operations / Web promotion |

The seeder creates deterministic profiles for all accounts, Professor rows for the Semi-Coordinator, Registered Moderator, Event Staff, and Professor accounts, and Student rows for Requested Moderator and Student. It reuses the first valid active Course/Curriculum and Department reference data and creates no Enrollment, teaching, Grading, or Admission records. **IT Validation Event** targets all academic users, so the Student identities need no fabricated Enrollment. Existing fixture usernames `event_moderator` and `event_classmayor` are renamed in place when possible so their identity is preserved.

The Event seeder is included in normal `local`/`testing` `DatabaseSeeder` runs after baseline account and academic reference data. It can also be run explicitly and safely rerun:

```bash
cd /home/kenneth/projects/CDM_Portal/backend
php artisan db:seed --class=EventRoleTestingSeeder
```

The explicit command creates or updates only the seven dedicated accounts, their required identity rows, **IT Validation Event**, and its four elevated responsibility assignments. It removes stale personnel assignments from that fixture-owned Event while preserving Event audit history and unrelated Event records.

Start Desktop for Coordinator and Semi-Coordinator:

```bash
cd /home/kenneth/projects/CDM_Portal/CDM_Frontend
npm run desktop:dev
```

Start Mobile for Registered Moderator, Event Staff, Professor, Requested Moderator, and Student:

```bash
cd /home/kenneth/projects/CDM_Portal/CDM_Frontend
npm run mobile:dev -- --host=0.0.0.0
```

### Event manual test matrix

- **Coordinator / Desktop:** create and edit an Event; manage Personnel; assign and revoke responsibilities; control QR/session; monitor/correct attendance; open reports and export CSV.
- **Coordinator / Web:** confirm only the promotion-safe Event page is available and administration is absent.
- **Semi-Coordinator / Desktop:** edit IT Validation Event and its audience, add a direct sub-event, view Personnel, control its attendance session, manually verify attendance, and view its report. Confirm top-level creation, lifecycle transitions, personnel writes, attendance correction, CSV export, audit, and unrelated Events are blocked.
- **Semi-Coordinator / Web:** confirm promotion-only viewing. On Mobile, confirm operational Event access is denied.
- **Registered Moderator / Mobile:** scan a Student's static participant QR and monitor only the assigned Event. Confirm dynamic workflow and administration are blocked.
- **Event Staff / Mobile:** use assigned manual verification and monitoring. Confirm moderator QR privileges and administration are absent.
- **Requested Moderator / Mobile:** scan a Student's short-lived dynamic participant QR, self-check in when eligible, and confirm static workflow and unrelated Events are blocked.
- **Professor / Mobile:** confirm eligible Event participation without personnel controls. On Web, confirm promotion only.
- **Student / Mobile:** view eligible Events, self-check in, show participant QR credentials, and view own attendance. On Web, confirm promotion only.
- **Guest / Web:** confirm promotion-safe Events are visible while every operational Event endpoint is denied.
