# CDM Buddy integration and Event module separation

Implementation branch: `feature/cdm-buddy-event-separation`

Baseline: deployed `origin/main` at `b4e4c84`

Buddy design source: `origin/feature/cdm-buddy-widget` at `eadfdfc`

## Scope and integration decisions

The approved mountain-explorer mascot was ported from the feature branch instead of merging that branch. Its original inline SVG is retained, so no third-party artwork, remote asset, or new image license is introduced. The SVG is compact, resolution independent, and adds no network request.

CDM Buddy is mounted only on the authenticated Student and Guest dashboards. It supports minimized, expanded, and session-dismissed states, Escape-to-minimize, focus restoration, reduced motion, safe-area spacing, and an in-flow mobile layout that cannot cover mobile navigation or dashboard controls.

The widget distinguishes `Verified update` records from `Information`. If the approved endpoint has no records or is unavailable, it shows short local guidance and does not invent an announcement, date, weather advisory, or class suspension.

## File change manifest

The branch changes 116 paths: 6 added, 39 modified, and 71 removed. The authoritative per-path list is available with `git show --name-status --format= HEAD` after checkout.

Added:

- `CDM_Frontend/src/components/CDMBuddy.vue`
- `CDM_Frontend/src/services/portalNoticeService.js`
- `CDM_Frontend/test/cdmBuddyAndEventSeparation.test.js`
- `backend/app/Http/Controllers/Api/PortalNoticeController.php`
- `backend/tests/Feature/PortalNoticeTest.php`
- `docs/CDM_BUDDY_EVENT_SEPARATION.md`

Modified files cover the frontend shell, dashboards, router/access/navigation, public-home content/styles, offline store, shared regression tests and Android wrapper; backend routes/bootstrap, dashboard services/models, shared regression tests and seed registration; and the root/frontend/platform/Admissions/Monitoring documentation.

Removed files cover the complete `CDM_Frontend/src/modules/event-attendance/` tree, Event carousel and Event-only frontend tests; Laravel Event controllers, middleware, models, notifications, services, seeder, report views and verification script; Event-only backend tests; and superseded Event/readiness documents. No migration or stored data file is removed.

## Approved Buddy data sources

`GET /api/portal-notices` is authenticated, client-platform protected, throttled, private/no-store, and available only to Guest and Student roles.

| Source | Visibility | Data shown |
| --- | --- | --- |
| Admission application | Owning Guest only | Current persisted application status and update time |
| Enrollment database notifications | Notification recipient only | Transactional status/schedule/load message created by Enrollment services |
| Academic Monitoring risk notifications | Owning Student only | Staff-authored title/message already visible in My Notices |

There is no approved general bulletin, weather, or class-suspension publication source in this repository. CDM Buddy therefore does not display those categories. A future source must enforce publication state and role visibility on the server before being added.

## Event dependency inventory and removal

Removed runtime dependencies:

- Vue routes, role guards, navigation, views, services, carousel, dashboard cards, login-page placeholders, and Event-specific tests.
- Laravel Event routes, controllers, services, middleware, models, notifications, console reminder schedule, local seeder, report/certificate templates, verification script, and Event-specific tests.
- Student/Admin dashboard Event queries and payload fields, Admin review/activity aggregation, Event relationships on shared User/Student models, and stale Event-only CSS selectors.

Shared dependencies deliberately retained:

- Authentication, client-platform policy, roles, step-up authentication, database notifications, generic QR packages, generic PDF/XLSX packages, shared dialogs/tables, and all academic/registrar services.
- All Event schema migrations and database tables. They preserve historical rows and rollback history but are no longer referenced by application runtime code.

Expected remaining Event references after implementation are limited to the retained `backend/database/migrations/*event*` files and this retention/separation record. Physical table or historical-record removal requires separate approval and a dedicated backup/retention plan.

## Database changes

No new migration is proposed or executed. No Event table, row, attachment, notification row, or backup is deleted. The existing Event migrations remain in source control so current and new environments retain schema history.

## Validation results

Validated on October 21, 2026:

| Check | Result |
| --- | --- |
| Full Laravel suite (`vendor/bin/phpunit --colors=never`) | Pass: 441 tests, 4,115 assertions |
| Full Vue suite (`npm test`) | Pass: 39 tests |
| Website production bundle | Pass with the production API URL supplied at build time |
| Capacitor production web bundle and Android sync | Pass |
| Native Android release (`./gradlew assembleRelease`) | Environment-blocked: this validation host has no JDK or `JAVA_HOME`; no source/build failure was reached |
| Electron production build | Pass: Linux AppImage produced; packaging reports only missing optional desktop name/category metadata |
| Laravel Pint (`vendor/bin/pint --test`) | Pass |
| Whitespace validation (`git diff --check`) | Pass |

The Android Gradle wrapper was repaired as part of validation: its malformed first line, CRLF line endings, and missing executable bit prevented the wrapper from starting independently of application code. A native Android release must still be run on the release host with the project's supported JDK before distribution.

## Manual UAT checklist

- Sign in as Guest on Website; confirm CDM Buddy appears only on Guest Dashboard and shows only that account's Admission/Enrollment update or labeled informational guidance.
- Sign in as Student on Website and Android; confirm only the Student's Enrollment and Academic Monitoring messages appear.
- Expand, minimize with the close button and Escape, cycle messages, follow a Portal link, dismiss for the session, and confirm it returns in a new browser/app session.
- Navigate away from the dashboard; confirm Buddy is absent. Return and confirm the expected state.
- At phone widths and with large text, confirm the widget is in document flow and does not cover navigation or controls. Enable reduced motion and keyboard-only navigation.
- Confirm `/event-attendance`, `/api/events`, `/api/event-promotions`, `/api/event-reports`, and the public certificate verification URL return 404 and no navigation links point to them.
- Exercise Admissions, Enrollment, Student Records, Document Requests/claim-code email, Professor grading/bulk entry, Academic Monitoring/AI Help, academic-year/curriculum settings, Registrar Electron sign-in, and each supported role/platform login.
- Confirm existing Event database tables and rows remain unchanged with a read-only database inspection.

## Platform impact

- Website: Student and Guest dashboards gain Buddy; all Event promotion and management surfaces are removed.
- Android/Capacitor: Student dashboard gains the responsive Buddy; Event participation, attendance, moderator, and participant-QR surfaces are removed.
- Electron: Admin and Registrar academic/administrative access is unchanged; Event administration and reports are removed.

## Rollout and rollback

1. Back up the application database and uploaded files. Do not drop Event tables.
2. Deploy backend and frontend from the same reviewed commit so removed frontend calls cannot reach stale routes and removed routes cannot be linked by stale bundles.
3. Clear Laravel route/config caches, restart queue/Octane workers, publish the Website bundle, then distribute matching Android and Electron builds.
4. Run the UAT checklist and monitor 404/403/5xx rates for `/api/portal-notices` and dashboard endpoints.

Rollback is an application-code rollback to the previous approved release plus restoration of its matching Website/Android/Electron artifacts. No database rollback is required because this change runs no migration and leaves Event schema/data intact. If a future cleanup migration is proposed, it must have a separately tested data restore procedure before approval.
