# CDM Portal IT Validation Checklist

Validation date: __________  
Build/commit identifier: __________  
Backend host: __________  
Web URL: __________  
Desktop build: __________  
Mobile device/OS: __________  
Validated by: __________

Use the accounts recorded in `docs/IT_TEST_ACCOUNTS.md`. Never write passwords, bearer tokens, QR payloads, or other secrets in this checklist. Mark every row and record a ticket/reference in Notes for failures.

## Preflight and environment

| Action | Expected result | Result | Notes |
| --- | --- | --- | --- |
| Run `php artisan migrate:status` from `backend`. | Every migration is `Ran`; Event foundation, attendance, and reports/sub-events appear in filename order. | ☐ Pass ☐ Fail | __________ |
| Confirm the validation database backup/restore procedure. | A current backup exists and the operator knows the restore location and owner. | ☐ Pass ☐ Fail | __________ |
| Confirm `APP_ENV` matches the target environment and `APP_DEBUG=false`. | Debug pages and stack traces are disabled. | ☐ Pass ☐ Fail | __________ |
| Confirm `APP_URL` is reachable from the intended clients. | Generated URLs resolve to the validation backend. | ☐ Pass ☐ Fail | __________ |
| Confirm `VITE_API_BASE_URL` is reachable from web and the physical mobile device. | `/api` requests reach the validation backend without a proxy/tunnel error. | ☐ Pass ☐ Fail | __________ |
| Confirm `VITE_DESKTOP_API_BASE_URL` resolves on the Electron host. | Packaged desktop requests reach the validation backend. | ☐ Pass ☐ Fail | __________ |
| Review `CORS_ALLOWED_ORIGINS`. | Only current validation origins are listed; no obsolete tunnel domain is present. | ☐ Pass ☐ Fail | __________ |
| Confirm MariaDB connectivity and storage permissions. | API connects; `storage/app/private` and `storage/app/public` are writable; `public/storage` link exists. | ☐ Pass ☐ Fail | __________ |
| Start queue processing if document AI/queued work is included. | Queue worker starts without configuration errors. | ☐ Pass ☐ Fail | __________ |

## Registrar desktop

Use the Electron desktop build. A Registrar bearer session is valid only with `X-CDM-Client: desktop`.

| Action | Expected result | Result | Notes |
| --- | --- | --- | --- |
| Launch the packaged Electron application and sign in as active Registrar Staff. | Login succeeds and the Registrar dashboard renders without a blank state. | ☐ Pass ☐ Fail | __________ |
| Review dashboard counts against the cleaned operational database. | Counts reflect real records only; no LargeDataset Students appear. | ☐ Pass ☐ Fail | __________ |
| Open Admission Applicants, Programs, Exams, Exam Questions, Results, and History. | Every workspace loads; search/filter/detail actions work and authorized controls are visible. | ☐ Pass ☐ Fail | __________ |
| Inspect an Admission applicant. | Detail opens in the current modal and no stale inline/placeholder detail appears. | ☐ Pass ☐ Fail | __________ |
| Open Enrollment applications, Sections, Scheduling, final Enrollment, roster/COR surfaces. | Lists and forms load with clear content, empty, or error states. | ☐ Pass ☐ Fail | __________ |
| Open Student Management and inspect the remaining operational Student. | Student identity, profile, current/latest academic state, documents, and history agree. | ☐ Pass ☐ Fail | __________ |
| Open active Document Requests and History. | Clean empty/content states render; filters do not return removed fixtures. | ☐ Pass ☐ Fail | __________ |
| Open Appointments and Physical Records. | Appointment and location totals match the operational database. | ☐ Pass ☐ Fail | __________ |
| Open Monitoring. | Authorized monitoring pages render without fixture rows or blank routes. | ☐ Pass ☐ Fail | __________ |
| Create or open a published Event with an eligible Student audience. | Event definition, audience, calendar/detail, and sub-event controls work. | ☐ Pass ☐ Fail | __________ |
| Open an Event attendance session. | Staff panel shows a rotating QR and live roster; raw secret/token fingerprint is not shown. | ☐ Pass ☐ Fail | __________ |
| Open Event Reports and export CSV. | Summary, filters, attendance rows, and download work. | ☐ Pass ☐ Fail | __________ |
| Open Settings; update an allowed profile/contact field and appearance. | Changes persist; role, status, and institutional identity remain read-only. | ☐ Pass ☐ Fail | __________ |
| Change password using the current password, then sign back in. | Correct current password is required and the new credential works. | ☐ Pass ☐ Fail | __________ |
| Sign out. | Token/session is revoked and protected routes return to Login. | ☐ Pass ☐ Fail | __________ |
| Attempt Registrar login from the normal web build and mobile build. | Safe HTTP 403 response; no token is issued and no redirect loop/blank screen occurs. | ☐ Pass ☐ Fail | __________ |

## Admin web and desktop

Admin is supported on `web` and `desktop`, but not `mobile`.

| Action | Expected result | Result | Notes |
| --- | --- | --- | --- |
| Sign in through the web build as active Admin. | Login succeeds with Admin navigation. | ☐ Pass ☐ Fail | __________ |
| Sign in through Electron as active Admin. | Login succeeds and the desktop-bound session works. | ☐ Pass ☐ Fail | __________ |
| Open the unified Admission workspace and exercise read/configuration actions. | Admin and Registrar Admission capabilities match inside Admission. | ☐ Pass ☐ Fail | __________ |
| Open authorized Enrollment management surfaces. | Lists/actions load and preserve workflow eligibility/version rules. | ☐ Pass ☐ Fail | __________ |
| Create/edit an Event and review audience settings. | Authorized Event management succeeds and audit data is written. | ☐ Pass ☐ Fail | __________ |
| Open Event Reports, filter, and export CSV. | Report and export succeed on both supported Admin platforms. | ☐ Pass ☐ Fail | __________ |
| Open Admin/Registrar Settings route where exposed. | Own allowed profile/theme/password controls work; role/status cannot be changed. | ☐ Pass ☐ Fail | __________ |
| Navigate across authorized modules and refresh deep links. | Active sidebar state is correct; no duplicate entries, phase labels, blank views, or redirect loops. | ☐ Pass ☐ Fail | __________ |
| Attempt Admin login from mobile. | Safe HTTP 403 response and no token is issued. | ☐ Pass ☐ Fail | __________ |
| Sign out from each client. | Each session is revoked without affecting unrelated valid sessions except where policy intentionally revokes them. | ☐ Pass ☐ Fail | __________ |

## Student web

| Action | Expected result | Result | Notes |
| --- | --- | --- | --- |
| Sign in through the web build as the active Student. | Login succeeds; Student dashboard and navigation render. | ☐ Pass ☐ Fail | __________ |
| Open Enrollment status/workflow. | Own current application/status appears; continuing-Student access does not require Admission history. | ☐ Pass ☐ Fail | __________ |
| Open Document Requests and create/cancel only where current rules allow. | Own requests only are visible; state transitions follow existing rules. | ☐ Pass ☐ Fail | __________ |
| Open Events and an Event detail. | Only eligible/visible Events appear and only the Student's own attendance status is returned. | ☐ Pass ☐ Fail | __________ |
| Open Settings and upload a valid profile photo. | Photo appears in Settings/sidebar and Registrar Student surfaces after refresh. | ☐ Pass ☐ Fail | __________ |
| Upload an invalid avatar type or a file larger than 2 MB. | Safe validation error appears; prior photo remains intact. | ☐ Pass ☐ Fail | __________ |
| Switch light/dark/system appearance and refresh. | Selection persists and all Student pages remain readable. | ☐ Pass ☐ Fail | __________ |
| Attempt Registrar Student Management, Admission staff, Event Reports, and other administrative URLs directly. | Backend returns safe 403 responses; frontend shows Unauthorized rather than protected content. | ☐ Pass ☐ Fail | __________ |
| Sign out and use browser Back/refresh. | Protected content does not reappear and login is required. | ☐ Pass ☐ Fail | __________ |

## Student mobile

Install a build produced by `npm run mobile:build` plus the platform sync/package procedure. Use a physical device on a network that can reach `VITE_API_BASE_URL`.

| Action | Expected result | Result | Notes |
| --- | --- | --- | --- |
| Sign in as active Student. | Mobile-bound login succeeds and dashboard renders at device width. | ☐ Pass ☐ Fail | __________ |
| Open Enrollment, Document Requests, Events, Settings, and profile photo controls. | Controls remain visible and usable without horizontal page overflow. | ☐ Pass ☐ Fail | __________ |
| Open the Event QR scanner and grant camera permission. | Permission prompt is clear and scanner initializes. | ☐ Pass ☐ Fail | __________ |
| Confirm the rear/environment camera is selected where the device supports it. | Rear camera opens; operator can switch/fallback when multiple cameras exist. | ☐ Pass ☐ Fail | __________ |
| Scan a current QR for an eligible Event. | One attendance record is created and a success/attendance status appears. | ☐ Pass ☐ Fail | __________ |
| Scan the same valid QR again. | Response reports already recorded; no duplicate attendance row is created. | ☐ Pass ☐ Fail | __________ |
| Scan a rotated/previous QR. | Safe invalid-token response; no attendance row is created. | ☐ Pass ☐ Fail | __________ |
| Scan after token expiry. | Expired-token response is shown and no attendance is recorded. | ☐ Pass ☐ Fail | __________ |
| Scan after staff closes the session. | Session-closed response is shown and no new attendance is recorded. | ☐ Pass ☐ Fail | __________ |
| Scan as a Student outside the Event audience. | Not-eligible response is shown without disclosing another Student's data. | ☐ Pass ☐ Fail | __________ |
| Interrupt Wi-Fi/data during scan, then restore it. | Clear network error appears; UI does not claim success; retry succeeds safely after recovery. | ☐ Pass ☐ Fail | __________ |
| Deny camera permission and use the supported image/manual fallback where available. | Page remains usable and gives a recovery path instead of becoming blank. | ☐ Pass ☐ Fail | __________ |
| Restart the mobile app. | Persisted login is restored only while token remains valid; profile/theme remain consistent. | ☐ Pass ☐ Fail | __________ |
| Sign out. | Local auth state clears and protected views require login. | ☐ Pass ☐ Fail | __________ |

## Professor web

| Action | Expected result | Result | Notes |
| --- | --- | --- | --- |
| Sign in through web as active Professor. | Login succeeds and Professor dashboard renders. | ☐ Pass ☐ Fail | __________ |
| Open schedule and assigned class/roster surfaces. | Only assigned/current academic data appears. | ☐ Pass ☐ Fail | __________ |
| Open Events and inspect a visible Event. | Read-only Event information appears; attendance management/report controls are absent. | ☐ Pass ☐ Fail | __________ |
| Attempt Registrar/Admin, Admission staff, Enrollment management, Student Management, and Event Reports routes. | Backend returns safe 403; no protected data renders. | ☐ Pass ☐ Fail | __________ |
| Attempt Professor login on desktop and mobile. | Safe 403 response; no token is issued. | ☐ Pass ☐ Fail | __________ |
| Sign out and refresh. | Protected routes require login. | ☐ Pass ☐ Fail | __________ |

## Physical two-device Event test

Prepare one published Event whose current time window is open and whose audience includes the test Student. Device A is the Registrar Electron desktop; Device B is the Student mobile build.

| Action | Expected result | Result | Notes |
| --- | --- | --- | --- |
| On Device A, open the attendance session and keep the QR panel visible. | QR rotates approximately every 30 seconds; session remains open. | ☐ Pass ☐ Fail | __________ |
| On Device B, scan the current QR. | API accepts attendance and returns present/late according to the 15-minute rule. | ☐ Pass ☐ Fail | __________ |
| Observe Device A after the scan. | Student appears once in the live attendance panel with matching status/time. | ☐ Pass ☐ Fail | __________ |
| Scan the same current QR again. | Duplicate is handled idempotently; the staff row remains single. | ☐ Pass ☐ Fail | __________ |
| Save a displayed QR, wait for rotation, and scan the old image. | Old token is rejected as invalid. | ☐ Pass ☐ Fail | __________ |
| Obtain a QR and wait beyond its expiry before scanning. | Expired token is rejected. | ☐ Pass ☐ Fail | __________ |
| Close the session on Device A and scan again. | Session-closed response; no new record. | ☐ Pass ☐ Fail | __________ |
| Open Event Reports on Device A. | Summary and Student row match the accepted attendance. | ☐ Pass ☐ Fail | __________ |
| Export filtered CSV and open it locally. | Event metadata and the correct Student row/status appear; filtering matches the screen. | ☐ Pass ☐ Fail | __________ |

## File and storage validation

Expected storage:

- Student avatars: public disk, `storage/app/public/student-avatars/`, served through `public/storage`.
- Staff avatars: public disk, `storage/app/public/staff-avatars/`, served through `public/storage`.
- Registrar Student documents: private local disk, `storage/app/private/student-documents/{student_id}/{document_type_id}-{type}/`.
- Enrollment uploads: private local disk, `storage/app/private/enrollment/{application_id}/`.

| Action | Expected result | Result | Notes |
| --- | --- | --- | --- |
| Upload JPG/PNG/WebP profile photo under 2 MB. | File is stored on the public disk and the API returns a relative `/storage/...` URL. | ☐ Pass ☐ Fail | __________ |
| Refresh web/mobile/desktop views that already display an avatar. | Same UserProfile image appears; initials are used if image is absent/unavailable. | ☐ Pass ☐ Fail | __________ |
| Upload an allowed Student document (PDF/JPEG/PNG within configured size). | File receives a generated name under the Student-specific private directory. | ☐ Pass ☐ Fail | __________ |
| View and download the document through the authorized Registrar endpoint. | Content is returned with private/no-store and `nosniff` headers; direct public path access is unavailable. | ☐ Pass ☐ Fail | __________ |
| Attempt invalid MIME, oversized upload, and path-like filename. | Validation/storage rejects unsafe input; no partial database/file state remains. | ☐ Pass ☐ Fail | __________ |
| Restart backend and client processes, then repeat view/download/avatar load. | Stored files persist and URLs/endpoints still work. | ☐ Pass ☐ Fail | __________ |

## Event CSV export validation

Expected filename: `{slugged-event-title}-attendance-YYYY-MM-DD.csv`.

| Action | Expected result | Result | Notes |
| --- | --- | --- | --- |
| Export as Registrar desktop. | Download succeeds and creates a `report.exported` audit event. | ☐ Pass ☐ Fail | __________ |
| Export as Admin web and Admin desktop. | Download succeeds on both supported platforms. | ☐ Pass ☐ Fail | __________ |
| Attempt export as Student, Professor, and Guest. | Safe 403 response; no file content is returned. | ☐ Pass ☐ Fail | __________ |
| Apply status/course/year/Section/search filters before export. | CSV rows respect the same filters as report detail. | ☐ Pass ☐ Fail | __________ |
| Review metadata and summary rows. | Event, schedule, venue, audience, generation actor/time, totals, and attendance rate are present. | ☐ Pass ☐ Fail | __________ |
| Review Student columns. | Student number/name, course/year/Section snapshot, check-in time, status, source, and recorder are correct. | ☐ Pass ☐ Fail | __________ |
| Search CSV for secrets/internal scan data. | No QR payload, token fingerprint/version, scan log, bearer token, or internal secret appears. | ☐ Pass ☐ Fail | __________ |
| Inspect response filename and headers. | Filename is slug/date based; content type is CSV and cache is private/no-store. | ☐ Pass ☐ Fail | __________ |

## Platform, session, and token security

| Action | Expected result | Result | Notes |
| --- | --- | --- | --- |
| Attempt Registrar login from web and mobile. | HTTP 403; no token and no `last_login` update. | ☐ Pass ☐ Fail | __________ |
| Attempt Student login from desktop. | HTTP 403; no token. | ☐ Pass ☐ Fail | __________ |
| Attempt Professor login from desktop/mobile. | HTTP 403; no token. | ☐ Pass ☐ Fail | __________ |
| Attempt Admin login from mobile. | HTTP 403; no token. | ☐ Pass ☐ Fail | __________ |
| Send authenticated request without `X-CDM-Client`. | Safe 4xx response identifying the missing client; no protected payload. | ☐ Pass ☐ Fail | __________ |
| Send invalid `X-CDM-Client`. | Safe 4xx response; no protected payload. | ☐ Pass ☐ Fail | __________ |
| Replay a valid token with a different client header. | HTTP 403 because the token ability does not match the client. | ☐ Pass ☐ Fail | __________ |
| Call protected API as authenticated Guest. | Only applicant self-service permissions work; staff/academic operations return 403. | ☐ Pass ☐ Fail | __________ |
| Refresh a protected page with a valid session. | Authentication restores and the same authorized route renders. | ☐ Pass ☐ Fail | __________ |
| Restart the client with a valid stored token. | Session restores only on the same allowed client. | ☐ Pass ☐ Fail | __________ |
| Log out, then replay the revoked token. | API returns 401 and client clears local auth state. | ☐ Pass ☐ Fail | __________ |
| Suspend/deactivate an account while its token exists. | Next protected request returns 403 and exposes no module data. | ☐ Pass ☐ Fail | __________ |
| Observe all rejected cases in the UI. | No redirect loop, white/blank page, stack trace, or credential detail appears. | ☐ Pass ☐ Fail | __________ |

## Dark mode and responsive review

Repeat the relevant rows in light and dark mode at desktop width, approximately 768 px, and approximately 390 px where supported.

| Action | Expected result | Result | Notes |
| --- | --- | --- | --- |
| Inspect Dashboard, Admission, Enrollment, Student Management, Document Requests, Physical Records, Monitoring, Events, and Settings. | Text, surfaces, tables, dialogs, controls, and badges remain readable; no unintended white block appears. | ☐ Pass ☐ Fail | __________ |
| Trigger loading, empty, validation-error, server-error, and modal states where practical. | Each page presents an explicit state and never a blank RouterView. | ☐ Pass ☐ Fail | __________ |
| Inspect Student mobile navigation and forms. | Controls are reachable and no essential action is clipped or dependent on hover. | ☐ Pass ☐ Fail | __________ |
| Inspect Admin web at narrow width. | Navigation remains usable and tables provide controlled scrolling/wrapping. | ☐ Pass ☐ Fail | __________ |
| Inspect Event scanner/report, Document Requests, Enrollment, and Settings at narrow width. | Filters/actions remain accessible and do not overlap. | ☐ Pass ☐ Fail | __________ |
| Enable reduced-motion preference. | Nonessential motion is reduced without hiding state changes. | ☐ Pass ☐ Fail | __________ |

## Restart and recovery

Do this after at least one upload, one valid login on each client, and one Event attendance/report record.

| Action | Expected result | Result | Notes |
| --- | --- | --- | --- |
| Restart Laravel Octane/FrankenPHP using the deployment service command. | Health/API requests recover; database state is unchanged. | ☐ Pass ☐ Fail | __________ |
| Restart Vite for development validation or redeploy the production `dist`. | Deep links and assets load; client platform remains correct for the build mode. | ☐ Pass ☐ Fail | __________ |
| Quit and relaunch Electron. | Renderer loads locally; desktop API endpoint works; valid desktop session restores safely. | ☐ Pass ☐ Fail | __________ |
| Force-close and relaunch the mobile app. | Mobile session/theme/profile persist as designed and scanner can reacquire camera permission. | ☐ Pass ☐ Fail | __________ |
| Re-open uploaded avatar/document. | Files persist across process restart. | ☐ Pass ☐ Fail | __________ |
| Re-open Event Reports and exported data. | Attendance database records persist and summaries remain correct. | ☐ Pass ☐ Fail | __________ |
| Restart while an attendance session is open. | Server remains authoritative; current session state reloads safely and stale QR tokens remain invalid. | ☐ Pass ☐ Fail | __________ |

## Final sign-off

### Preparation record — 2026-10-03

- Backend environment: `local`; debug: `false`; application URL: `http://127.0.0.1:8000`; database driver: `mysql`; default private filesystem: `local`.
- Web/mobile API build value: `http://192.168.100.34:8000/api`. Confirm this address belongs to the validation host and is reachable from the physical device before installation.
- Desktop API build value: `VITE_DESKTOP_API_BASE_URL` is not overridden in the local `.env`, so the desktop build uses `http://127.0.0.1:8000/api`.
- CORS includes local Vite origins plus `http://localhost`/`capacitor://localhost`; the obsolete Cloudflare tunnel origin was removed. Add any actual LAN-hosted web origin explicitly before testing from another computer.
- Public storage link exists. Public and private storage directories are writable.
- MariaDB has no pending migrations and the LargeDataset ownership ledger is empty.
- One active account exists for each required role. Credentials were not printed or added to documentation.
- Automated result: 322 backend tests/3,012 assertions, Pint, 19 frontend tests, web build, mobile build, and Electron Linux AppImage build passed.
- Desktop artifact: `CDM_Frontend/release/CDM Portal-0.1.0.AppImage`. Device launch/login remains a manual validation step.
- Physical camera, two-device QR, visual dark-mode/responsive, upload persistence, and process-restart rows remain deliberately unchecked until an operator performs them on target hardware.

| Gate | Expected result | Result | Notes |
| --- | --- | --- | --- |
| Backend tests and Pint | All pass. | ☐ Pass ☐ Fail | __________ |
| Frontend tests, web/mobile/desktop builds | All pass. | ☐ Pass ☐ Fail | __________ |
| Database migrations/integrity | No pending migration or broken reference. | ☐ Pass ☐ Fail | __________ |
| Role/platform matrix | Every allowed combination succeeds and every denied combination fails safely. | ☐ Pass ☐ Fail | __________ |
| Physical QR and storage tests | Two-device attendance and restart persistence pass. | ☐ Pass ☐ Fail | __________ |
| IT validation decision | ☐ Approved ☐ Approved with findings ☐ Rejected | — | Signatory/date: __________ |
