# CDM Portal Complete UI Rebuild

## Scope and reference

This rebuild uses the collaborators' [ENROLLMENT repository](https://github.com/ayumiswift16-ux/ENROLLMENT) at verified revision `b67543eb30c69548694659689f4e51a1b9d3dcb0` as the interaction and composition reference. Its compact controls, restrained surfaces, integrated list toolbars, focused record workspaces, sectioned forms, and modal hierarchy are translated into Vue without importing React, Firebase, Firestore, fake data, or reference business rules.

Status is deliberately strict:

- **Not started** — the route may inherit global navigation/tokens, but its page template has not been structurally rebuilt.
- **In progress** — meaningful structure changed, but the route remains mixed with legacy composition.
- **Implemented — visual review pending** — the route template/layout changed and interactions remain connected; rendered screenshot acceptance is still required.
- **Visually accepted** — requires user-reviewed screenshots or rendered UAT. No route has this status yet.
- **Blocked** — a specific safety boundary prevents work.

## Recovery checkpoint

Pre-change checkpoint: `/tmp/cdm-complete-ui-rebuild-prechange.20261030`.

- `tracked.patch`: SHA-256 `7e335213b87260a2199ec72749720e7f91d1bd5f270e7c55f648b7de9c14e6dc`
- `untracked.tar.gz`: SHA-256 `f38b08f06d4b7dcdea982a510c1369238dba3233114adbe9b63c12579109249a`
- 189 untracked paths, excluding existing `.local-recovery` archives.

## Global layout implementation

Website and Electron now use one collapsible navigation surface instead of a permanent icon rail plus a second contextual navigation column.

- Expanded: icon, label, active module, and active module destinations in one sidebar.
- Collapsed: 72px icon-only navigation with tooltips and active indicator.
- Website mobile: existing focus-trapped overlay drawer remains in use.
- Electron narrow windows: automatically use the compact navigation.
- Preferences are scoped by authenticated account and persist in local storage.
- Routes still come exclusively from the existing role/platform-filtered navigation store.
- Route transitions do not remount the navigation or reset its preference.

The old `WebsiteNavigationPanel` and `DesktopContextPanel` files remain temporarily for recovery compatibility but are no longer mounted by either shell. Their removal should occur only after visual acceptance and a final dependency search.

## Route-by-route completion inventory

### Public and Website Guest

| Route | Source | Previous → current structure | Preserved interactions | State / validation |
| --- | --- | --- | --- | --- |
| `/login`, `/register` redirect | `LoginView.vue` | Existing public/auth presentation; no structural change in this batch. | Login, registration wizard, Google auth. | Not started. |
| `/terms`, `/privacy` | `PublicLegalView.vue` | Existing legal document presentation. | Public document navigation. | Not started. |
| `/guest-dashboard` | `GuestWebsiteDashboard.vue` | Previously migrated to Design System 5 header, next-step workspace and official notices. | Admission routing and notices. | In progress; focused/full frontend coverage. |
| `/admission` | `AdmissionView.vue` | Existing Admission workflow. | Eligibility, application creation, uploads and state. | Not started. |
| `/admission/exam` | `AdmissionExamView.vue` | Existing secured Website examination interface. | Acknowledgement, autosave, finalization and Website-only guard. | Blocked from casual restructuring pending dedicated exam visual UAT. |
| `/admission/result` | `AdmissionResultView.vue` | Existing published-result view. | Publication confidentiality. | Not started. |
| `/admission/recommendation` | `AdmissionRecommendationView.vue` | Existing recommendation view. | Published guidance only. | Not started. |

### Website and Android Student

| Route | Source | Previous → current structure | Preserved interactions | State / validation |
| --- | --- | --- | --- | --- |
| `/student-dashboard` | `StudentWebsiteDashboard.vue` / `StudentDashboardView.vue` | Existing UI 2 Website dashboard and Android dashboard. | Dashboard API, profile panel, notices, Buddy. | Not started in this rebuild. |
| `/enrollment`, `/enrollment/status` | `EnrollmentView.vue` → `EnrollmentStudent.vue` | Existing UI 4 application workflow. | Eligibility, draft lifecycle, cancel/reapply contract. | In progress from prior phase; unapplied migration untouched. |
| `/enrollment/courses` | `EnrollmentCourses.vue` | Design System 5 catalog composition. | Catalog endpoint and curriculum routing. | Implemented — visual review pending. |
| `/enrollment/courses/:courseId/curriculum` | `EnrollmentCurriculum.vue` | Existing curriculum workspace. | Real curriculum data. | Not started. |
| `/enrollment/steps` | `EnrollmentSteps.vue` | Existing guide composition. | Informational navigation. | Not started. |
| `/enrollment/subjects` | `EnrollmentAcademicView.vue` → `EnrollmentAcademicStudent.vue` | Existing subject record. | Finalized enrollment data. | Not started. |
| `/enrollment/schedule` | `EnrollmentAcademicView.vue` → `EnrollmentSchedule.vue` | Existing timetable. | Schedule API and term selection. | Not started. |
| `/enrollment/cor` | `EnrollmentAcademicView.vue` → `EnrollmentCor.vue` | Existing COR list/preview/print. | Official content and print. | Blocked from broad styling; interactive shell pending while print remains frozen. |
| `/grading` | `GradingView.vue` → `StudentGradesView.vue` | Existing published grade workspace. | Published grades only. | Not started. |
| `/monitoring`, `/monitoring/study-plans` | `MonitoringView.vue`, `StudyPlansView.vue` | Existing Monitoring and AI Help. | Authorized evidence and AI Help. | Not started. |
| `/document-requests` | `StudentDocumentRequestView.vue` | Existing request workspace. | Requests, appointments, claim codes. | Not started. |
| `/document-requests/appointments` | Router redirect | Redirects to Document Requests with query retained. | Query preservation. | Implemented redirect; no page UI. |
| `/settings` | `SettingsView.vue` | Existing Website Settings workspace. | Profile, photo, account-scoped appearance. | In progress from prior UI work. |

### Website and Android Professor

| Route | Source | Previous → current structure | Preserved interactions | State / validation |
| --- | --- | --- | --- | --- |
| `/professor-dashboard` | `ProfessorWebsiteDashboard.vue` / `ProfessorDashboardView.vue` | Existing role dashboard. | Dashboard API and authorized actions. | Not started. |
| `/enrollment/teaching` | `EnrollmentProfessor.vue` | Design System 5 cards, assignment list and roster table. | Assignment and roster APIs. | Implemented — visual review pending. |
| `/grading` | `GradingView.vue` | Feature-frozen spreadsheet. | Calculations, clipboard, undo/redo, recovery, Excel, drafts. | Blocked by explicit freeze; no internal change. |
| `/grading/submission` | `ProfessorGradeSubmissionView.vue` | Existing submission workspace. | Readiness and Registrar handoff. | Not started. |
| `/monitoring`, `/monitoring/adviser-alerts` | `MonitoringView.vue`, `AdviserAlertsView.vue` | Existing Monitoring views. | Adviser authorization. | Not started. |
| `/professor/settings` | `SettingsView.vue` | Existing Website-only Professor Settings. | Appearance and supported profile controls. | In progress from prior UI work. |

### Electron Registrar and Admin — Admission

| Route | Source | Previous → current structure | Preserved interactions | State / validation |
| --- | --- | --- | --- | --- |
| `/registrar/admissions` | `AdmissionRegistrarView.vue` | Filter row + basic table + inspection modal → integrated search/filter card, compact applicant table, selected-row feedback, persistent inline applicant detail and handoff workspace. | Search parameters, applicant detail API, exam link, handoff policy/actions. | **Implemented — visual review pending**; Admission and build regressions pass. |
| `/registrar/admissions/results` | `AdmissionRegistrarView.vue` | Disconnected toolbar/table → status tabs, integrated filters, selection batch bar, compact result grid and structured dialogs. | Approve, publish, correct, override, versions and step-up auth. | **Implemented — visual review pending**. |
| `/registrar/admissions/history` | `AdmissionRegistrarView.vue` | Basic history table → integrated searchable history workspace and structured event detail. | Client-side history search and audit detail. | **Implemented — visual review pending**. |
| `/registrar/admissions/review` | Router redirect | Redirect to Results. | Authorization and redirect. | Implemented redirect; no UI. |
| `/admin/admissions/programs` | `AdmissionAdminView.vue` | Existing program management. | Academic-field editing and permissions. | Not started. |
| `/admin/admissions/exams` | `AdmissionExamsView.vue` | Existing exam policy/readiness workspace. | Policy versions and readiness. | Not started. |
| `/admin/admissions/questions` | `AdmissionAdminView.vue` | Existing question management. | Question validation and permissions. | Not started. |
| `/admin/admissions/cycles` | Router redirect | Redirect to Exams. | Authorization and redirect. | Implemented redirect; no UI. |

### Electron Registrar and Admin — remaining modules

| Route | Source | Previous → current structure | Preserved interactions | State / validation |
| --- | --- | --- | --- | --- |
| `/registrar-dashboard` | `RegistrarDashboardView.vue` | Existing Desktop UI 3 operational queue. | Dashboard metrics/actions. | In progress from prior phase. |
| `/admin-dashboard` | `AdminDashboardView.vue` | Design System 5 header/loading/summary cards; other panels legacy. | Dashboard API. | In progress. |
| `/admin/registrars`, `/registrar/professors` | `AccountProvisioningView.vue` | Shared header/actions/badges/dialogs; list remains compatibility layout. | Provision, activate/deactivate, validation. | In progress. |
| `/campus-notices` | `CampusNoticesView.vue` | Design System 5 form/list/status composition. | Audience, official source, publish/archive/acknowledgement. | Implemented — visual review pending. |
| `/enrollment/applications`, `/enrollment/periods` | `EnrollmentView.vue` | Existing UI 4 list/detail redesign checkpoint. | Review, decisions, period lifecycle. | In progress; screenshot acceptance pending. |
| `/enrollment/sections`, `/enrollment/scheduling`, `/enrollment/records` | `EnrollmentAcademicView.vue` staff modes | Existing staff forms/tables. | Section, schedule and record APIs. | Not started. |
| `/enrollment/subject-management` | `EnrollmentSubjectManagement.vue` | Existing subject management. | Subject APIs and validation. | Not started. |
| `/registrar/document-types` | `DocumentTypesManagementView.vue` | Existing management table/dialog. | Document type rules. | Not started. |
| `/registrar/document-requests` | `RegistrarDocumentRequestView.vue` | Existing request queue. | Verification, release and claim workflow. | Not started. |
| `/registrar/appointments` | `RegistrarAppointmentsView.vue` | Existing appointments/release workspace. | Appointment and release APIs. | Not started. |
| `/registrar/document-requests/history` | `RegistrarDocumentRequestHistoryView.vue` | Existing audit/history table. | History API. | Not started. |
| `/student-management` | `StudentRecordsView.vue` | Existing Desktop UI 3 dense record workspace. | Search, filters, bulk and student routing. | In progress from prior phase. |
| `/student-management/:id` | `StudentProfileView.vue` | Existing student detail. | Authorized record viewing/editing. | Not started. |
| `/student-management/:id/documents` | `StudentDocumentsView.vue` | Existing physical/digital documents workspace. | Document tracking. | Not started. |
| `/student-management/physical-records` | `PhysicalRecordsView.vue` | Existing physical record workspace. | Location and custody tracking. | Not started. |
| `/student-management/reports` | `StudentRecordReportsView.vue` | Existing reports. | Report APIs. | Not started. |
| `/grading` | `GradingView.vue` staff modes | Existing grading administration. | Grade periods/review. | Not started; Professor spreadsheet freeze remains respected. |
| `/grading/student-history` | `StudentGradeHistoryView.vue` | Existing grade history. | Authorized history API. | Not started. |
| `/monitoring`, `/monitoring/adviser-alerts`, `/monitoring/study-plans` | Monitoring views | Existing staff Monitoring views. | Academic authorization. | Not started. |
| `/registrar/settings` | `SettingsView.vue` | Existing Desktop Settings. | Account-scoped appearance. | Not started in this rebuild. |

### Shared utility routes

| Route | Source | State |
| --- | --- | --- |
| `/` | `DashboardView.vue` | Role redirect/landing behavior preserved; no independent module UI. |
| `/unauthorized` | `UnauthorizedView.vue` | Not started; authorization behavior preserved. |
| Catch-all | Router redirect | Preserved. |

Android uses the same authorized Student, Guest, and Professor route components where permitted. Entrance-exam taking remains Website-only. The Android shell still uses its existing touch navigation; a complete Android page-by-page structural pass remains **Not started** except the shared Teaching Assignments component.

## Files changed in this rebuild batch

- `CDM_Frontend/src/components/website/WebsiteNavigationRail.vue`
- `CDM_Frontend/src/composables/useWebsiteNavigation.js`
- `CDM_Frontend/src/layouts/WebsiteAppShell.vue`
- `CDM_Frontend/src/components/desktop/DesktopPrimaryRail.vue`
- `CDM_Frontend/src/layouts/DesktopAppShell.vue`
- `CDM_Frontend/src/assets/styles/desktop-ui-3.css`
- `CDM_Frontend/src/modules/admission/components/AdmissionStaffWorkspace.vue`
- `CDM_Frontend/src/modules/admission/AdmissionRegistrarView.vue`
- `CDM_Frontend/test/admissionIntegration.test.js`
- `CDM_Frontend/test/desktopUiThreeRegistrar.test.js`
- `CDM_Frontend/test/websiteNavigationStability.test.js`
- `CDM_Frontend/test/websiteSidebarStepOneOne.test.js`
- `docs/CDM_COMPLETE_UI_REBUILD.md`

## Validation and visual gate

Validation completed on 30 October 2026:

- Focused navigation, Admission, and design-system tests: passed.
- Full frontend suite: 79 test files passed, 0 failed.
- Website production build: passed using only the inert compile-time URL `https://build.invalid/api`.
- Android mobile-development build: passed.
- Electron production renderer build: passed using only the inert compile-time URL `https://build.invalid/api`.
- `git diff --check`: passed.

The frontend suite retains its pre-existing non-failing CommonJS warning for `import.meta` in the `clientPlatform.js` test bundle. No backend, migration, seeder, `.env`, MariaDB, deployment, or hosted-service operation was performed.

No browser/GUI executable is currently available, so no route is marked visually accepted. Manual acceptance must compare the rebuilt shell and Admission routes at the required Website, Electron, and Android viewports, with Light/Dark/System where supported.

## Remaining work

This batch does **not** complete every module. It completes the global Website/Electron navigation replacement and structurally rebuilds three Registrar Admission routes. The tables above are the authoritative remaining-page list. In particular, Android interiors, Student academic pages, Guest Admission pages, Professor non-grading pages, and most Registrar/Admin record-management pages still require template-level rebuilds in later batches.
