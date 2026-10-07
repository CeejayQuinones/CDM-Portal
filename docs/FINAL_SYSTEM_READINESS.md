# CDM Portal Final System Readiness

Audit date: 2026-10-03  
Branch: `feature-admission-integration`  
Status: **Ready for IT validation**

The application architecture and implemented workflows are suitable for capstone demonstration. The ledger-owned large-dataset fixtures were removed safely on 2026-10-03, and the Event Phase 2 and Phase 3 migrations are recorded as applied. Target-device and production-configuration checks remain part of IT validation.

## System architecture

| Layer | Technology | Responsibility |
| --- | --- | --- |
| API | Laravel 13 / PHP 8.3+ | Authentication, authorization, validation, transactions, workflow rules, reports, audit data |
| Browser UI | Vue 3, Vue Router, Pinia, Vite | Role-aware web interface and Student/Professor web clients |
| Desktop | Electron | Registrar desktop client and supported Admin client |
| Mobile | Capacitor | Student mobile client, including QR attendance scanning |
| Database | MariaDB | Relational workflow and academic records with foreign-key constraints |
| Authentication | Laravel Sanctum | Authenticated sessions/tokens with client-bound abilities |

The API is the security and business-rule boundary. Frontend navigation reflects permissions but does not grant them.

## Role and platform matrix

| Role | Desktop | Web | Mobile | Main scope |
| --- | --- | --- | --- | --- |
| Registrar Staff | Allowed | Denied | Denied | Admission, Enrollment, Student records, document operations, physical records, monitoring, Events, settings |
| Admin/System Admin | Allowed | Allowed | Denied | Admission and authorized system-wide management |
| Student | Denied | Allowed | Allowed | Own Admission history where applicable, Enrollment, document requests, Events/QR attendance, settings |
| Professor | Denied | Allowed | Denied | Assigned academic pages and read-only Event access |
| Guest/applicant | Denied | Public/applicant web only | Denied | Registration, verification, and own Admission workflow |

Authenticated API routes use `auth:sanctum` and `client.platform` before their role middleware or policy. A valid platform does not override module authorization.

## Module status

| Module | Status | Evidence and qualification |
| --- | --- | --- |
| Authentication/RBAC | Implemented | Active-account checks, role middleware/policies, client-platform enforcement, client-bound Sanctum abilities |
| Admission | Implemented | Guest application through exam/result and accepted-applicant conversion; Admin and Registrar have equal Admission workspace access; conversion reuses the User identity |
| Enrollment | Implemented | Application, classification, load, Section assignment, scheduling, finalization, COR, and Professor roster; continuing Students do not require Admission history |
| Student Management | Implemented | Durable Student records, academic history, current/latest term presentation, profile image reuse, and physical record links |
| Document Requests | Implemented | Student request flow, staff active/history/type views, appointments, status history, dialogs, loading/error/empty states |
| Monitoring | Implemented | Authorized academic monitoring surfaces with explicit loading/error/empty presentation |
| Events | Implemented | Event/audience/calendar, QR/manual attendance, reports/CSV, and optional sub-events; supporting migrations are applied |
| Settings | Implemented | Student and Registrar/Admin self-service profile, contact, avatar, password, and appearance settings; protected identity/role fields remain read-only |

## Data source-of-truth map

| Data | Authoritative source | Notes |
| --- | --- | --- |
| Login/account/role | `users` | Reused during Admission-to-Student conversion; no second login is created |
| Personal identity/photo | `user_profiles` | `profile_photo` is the single Student photo path; consumers use `profile_photo_url` and initials fallback |
| Durable Student identity | `students` | Student number, program/curriculum link, and durable academic identity |
| Admission history | `admission_*` tables | Retained after conversion and exposed read-only to the converted Student |
| Enrollment workflow | `enrollment_applications` | Pre-finalization workflow, including approved Section reservation |
| Final term/Section | `enrollments` | Current active finalized term is authoritative; historical fallback is explicitly labelled `latest` |
| Final subject load | `enrollment_subjects` | Finalized subject enrollment |
| Section offering/schedule | `sections`, `section_subjects` | Term-specific Section and scheduled subject data |
| Event definition/audience | `events`, `event_audiences` | Optional sub-events remain Events linked to a parent |
| Event attendance | `event_attendances` | QR and manual records converge on one attendance model |
| Physical file location | `student_record_locations` | Current physical record location and history |

Student Records shows the approved current Enrollment Application Section before finalization. Once finalized, `enrollments.section_id` is authoritative. Student Settings now follows the same finalized active-term rule, uses `academic_years.school_year`, and labels fallback data as `latest`.

## Security controls

- **RBAC:** backend role middleware, Admission boundaries, policies, and workflow services enforce access. Navigation visibility is supplementary.
- **Platform binding:** `X-CDM-Client` is checked at login and on protected requests. Bearer tokens carry exactly one matching `client:web`, `client:desktop`, or `client:mobile` ability.
- **Sanctum:** protected routes authenticate through Sanctum; inactive and suspended accounts are rechecked.
- **CORS:** configure explicit production origins. Local defaults are development-only and credential sharing is disabled by default.
- **Uploads:** avatar/document inputs validate type and size and use controlled storage paths. Clients receive relative public URLs rather than filesystem paths.
- **QR security:** Event QR payloads are authenticated/encrypted, short-lived, and tied to the current session fingerprint/version. Raw QR secrets are not returned by API resources.
- **Sensitive workflows:** result versioning, stale-update checks, latest-attempt rules, password step-up, conversion eligibility, and duplicate/concurrency protections remain server-side.
- **Audit trail:** Admission, Enrollment, Event, and document workflows retain their module audit/history records where implemented.
- **Write safety:** inspected sensitive writes use validated/explicit input and server-derived actors rather than unrestricted request mass assignment.
- **Secrets:** no live `.env` is tracked. Production must set `APP_ENV=production` and `APP_DEBUG=false` and inject secrets outside source control.

The client header is build-owned in the supported clients, but a header alone is not device attestation. The security boundary is the combined credential, client-bound token, active-account check, role authorization, and workflow validation.

## Known limitations

1. No approved complete production Admission question bank was found. Production correctly refuses to start an exam with an incomplete bank. The explicit development-only fixture must never be treated as production content.
2. The document AI integration defaults to a clearly identified mock development driver until a production provider is configured and validated.
3. Public login-page announcements are presentation data; there is no public announcements/CMS API in this scope.
4. Event report index summaries currently perform aggregate work per listed Event. The page is capped, but this is the clearest performance risk for a large Event catalog or large eligible audience.
5. Automated tests cover workflows and presentation contracts, but final IT validation still needs real desktop camera, mobile camera, filesystem, CSV download, printer/PDF, and target-network checks.

## Automated validation record

Core final validation was rerun successfully on 2026-10-03; mobile and desktop renderer builds passed during the immediately preceding integration pass with the same application source:

- Backend: 322 tests, 3,012 assertions.
- Laravel Pint: passed.
- Frontend: 19 tests passed.
- Web production build: passed.
- Capacitor/mobile renderer build: passed.
- Electron/desktop renderer build: passed.
- `git diff --check`: passed.

These results validate source behavior against isolated test databases. They do not substitute for the target-device checks below.

## Development-data cleanup

Before cleanup, the `generated_data_records` ledger identified **10,000** timestamp-verified Students owned by `school-demo-v1`/`school-demo-v2`. Their attached fixture rows were:

| Fixture data | Count |
| --- | ---: |
| Students/users/profiles | 10,000 each |
| Enrollment applications | 0 |
| Finalized enrollments | 0 |
| Student documents | 30,000 |
| Document requests | 50,000 |
| Appointments | 20,000 |
| Student record locations | 10,000 |

The preflight found no later non-fixture Enrollment, Admission conversion, Event attendance, risk, or Student Settings activity on those identities. The transactional cleanup removed all verified fixture rows, including 40,000 request-status rows, 800 cabinet slots, and 8 fixture cabinets. It retained zero Students. A second run found and deleted zero rows.

After cleanup, no `school-demo-v1` or `school-demo-v2` ledger rows remain. The operational database contains 1 Student, 5 Student documents, 1 Enrollment Application, 1 record location, and no document requests, request-history rows, appointments, finalized Enrollments, Events, Event audiences, or Event attendance. Registrar dashboard headline counts are: 1 Student, 0 pending requests, 0 appointments today, 0 Students without record locations, and 1 Student missing required documents.

For a local non-production cleanup, first back up and review the ledger, then run:

```bash
cd backend
php artisan db:seed --class=LargeDatasetCleanupSeeder
```

The cleanup seeder uses ledger ownership and recorded creation timestamps, deletes in foreign-key order inside a transaction, preserves Students with later non-fixture activity, and is idempotent. If fixtures are needed again for performance demonstrations, use a separate demo database.

## Migration order

The database reports no pending migrations. The Event migrations are recorded as applied in this dependency order:

1. `2026_10_02_000001_create_event_attendance_tables`
2. `2026_10_03_000001_add_event_reporting_and_sub_events`

Phase 3 follows Phase 2 because reporting and sub-event fields build on the Event attendance foundation. Future environments should apply the same filename order through the normal Laravel migrator.

## IT validation checklist

- [ ] Back up the target MariaDB database and confirm restore access.
- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, a production `APP_URL`, explicit CORS origins, production mail/storage settings, and protected secrets.
- [x] Apply Event Phase 2 then Phase 3 migrations and confirm `php artisan migrate:status` has no pending rows.
- [x] Remove ledger-owned fixtures with the guarded cleanup in the local validation database.
- [ ] Run backend tests and Pint, frontend tests, web build, and mobile build from the exact deployment revision.
- [ ] Smoke-test Registrar login only on Electron; Admin on web/desktop; Student on web/mobile; Professor on web; verify forbidden combinations return 403.
- [ ] Complete one Admission applicant journey and confirm conversion reuses the same User and retains Admission history.
- [ ] Complete one continuing-Student Enrollment without Admission history; verify Section, subjects, Professor roster, and COR after finalization.
- [ ] Complete a Student document request through appointment/release/history and inspect physical record location.
- [ ] Create an Event, target an audience, open a QR session, scan on a physical Student device, record a manual attendance, export CSV, and repeat for a sub-event.
- [ ] Verify Student Records, Student Settings, Events, and Professor roster agree on the finalized current Section; verify historical fallback says `Latest`.
- [ ] Test light/dark/system theme persistence, avatar fallback, loading/empty/error states, keyboard dialog behavior, and narrow mobile layouts.
- [ ] Validate uploads, generated URLs, CSV downloads, camera permissions, and storage permissions on target infrastructure.
- [ ] Configure a real Admission bank and production document AI provider before claiming those capabilities as production-ready.

## Oral defense talking points

- One User identity moves from Guest applicant to Student. Conversion creates the academic Student identity atomically and retains Admission history.
- Admission and Enrollment are separate histories: continuing Students can enroll without an Admission record.
- The approved Enrollment Application Section is visible before finalization; the finalized Enrollment becomes the official current academic source afterward.
- Authorization is layered: Sanctum identity, active-account status, platform-bound client ability, role/policy checks, then workflow rules.
- Event QR codes do not expose a reusable raw secret. Short-lived authenticated payloads resolve into the same attendance records used by manual staff entry and reporting.
- Development fixtures have explicit ledger ownership. The system can distinguish them for guarded cleanup and dashboard integrity without weakening relational constraints.
- The schema keeps workflow history separate from durable identities and finalized academic records, which makes audit, rollback, and reporting behavior explainable.
- The defensible readiness statement is conditional: implementation is complete for the capstone scope, while live IT validation requires the two migrations, a clean fixture state, production configuration, and device-level smoke tests.
