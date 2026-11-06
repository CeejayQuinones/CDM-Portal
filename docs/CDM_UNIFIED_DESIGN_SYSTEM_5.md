# CDM Portal Unified Design System 5.0

## Reference revision and conventions

The collaborators' [ENROLLMENT repository](https://github.com/ayumiswift16-ux/ENROLLMENT) was inspected at `main` commit `b67543eb30c69548694659689f4e51a1b9d3dcb0` (30 October 2026 audit). Directly reviewed files: `src/components/ui/Button.tsx`, `Card.tsx`, `Input.tsx`; `src/pages/Records.tsx`, `Courses.tsx`, `Enroll.tsx`, `Dashboard.tsx`, and `Steps.tsx`.

Reference conventions adopted natively in Vue:

- Compact 32/40/48px controls with primary, secondary, outline, ghost, and destructive hierarchy.
- Modest 6–10px radii, 1px neutral borders, restrained shadows, and neutral surfaces.
- Structured card header/content/footer regions.
- Labels, validation text, visible focus, disabled and loading states on form controls.
- Dense administrative tables in bounded, keyboard-scrollable surfaces.
- Modal header/content/footer hierarchy with focus containment, Escape handling, scroll locking, and trigger-focus restoration.

CDM forest green/emerald replaces the reference's blue primary. Academic gold is reserved for focus and warning emphasis. React, Firestore, fake counts, fees, capacities, and reference-only workflows were not copied.

## Existing styling architecture and conflicts

Before this work, `main.js` loaded legacy `main.css`; `WebsiteAppShell.vue` separately loaded Website UI 2.0, Emerald Academic, and academic-module CSS; `DesktopAppShell.vue` loaded Desktop UI 3.0; the Android fallback shell loaded legacy student portal/theme CSS. Individual modules additionally loaded `admission.css`, `enrollment.css`, `grading.css`, dashboard CSS, and account/document styles. Consequently, identical controls inherited different dimensions, colors, and radii based on shell and module specificity. UI 4.0 Registrar Enrollment was itself scoped to one module, so untouched pages could not inherit it.

Design System 5.0 does not override generic `button`, `input`, `table`, or card selectors. `main.js` now loads only semantic design tokens globally. Modules opt into scoped Vue primitives, allowing gradual migration without breaking frozen Professor Grading, official COR print rules, or platform navigation.

## Shared foundation

`CDM_Frontend/src/design-system/tokens.css` defines canvas, surfaces, borders, text hierarchy, CDM actions, semantic states, focus, spacing, radii, shadows, and control heights. Light is the default; existing `data-student-theme="dark"`, `student-portal-dark`, and System media behavior map the same tokens to dark values. Components consume only `--cdm-*` tokens.

Implemented primitives:

| Component | Contract |
| --- | --- |
| `CdmButton` | Button or RouterLink; primary/secondary/outline/ghost/danger; sm/md/lg/icon; loading, disabled, focus, reduced motion. |
| `CdmCard` | Structured optional header, padded content, and footer slots. Separate header/content/footer files were intentionally avoided because the current migrations only require one cohesive component. |
| `CdmInput`, `CdmSelect`, `CdmTextarea` | `v-model`, label, hint/error, semantic control sizing and focus. |
| `CdmBadge` | Neutral/success/warning/error status mapping. |
| `CdmTable` | Labelled keyboard-scrollable table region and empty state. It does not force desktop tables onto mobile modules. |
| `CdmDialog` | Teleported modal, accessible naming, focus trap, Escape/backdrop close, scroll lock, trigger restoration, wide mode, busy state. |
| `CdmPageHeader` | Consistent eyebrow/title/description/action hierarchy. |
| `CdmEmptyState`, `CdmLoadingState` | Shared empty/loading feedback with reduced-motion handling. |

`CdmTabs` was not created because none of the selected representative migrations required a new tab contract. Adding an unused abstraction would not advance adoption.

## Representative migrations completed

| Platform / role | Actual module | Shared adoption and preserved behavior |
| --- | --- | --- |
| Website Student | `/enrollment/courses` (`EnrollmentCourses.vue`) | Page header, loading/empty states, program cards, and curriculum action use shared primitives. Verified catalog API and curriculum route unchanged. |
| Website Guest | `/guest-dashboard` (`GuestWebsiteDashboard.vue`) | Shared page header, next-step card, and route action. Authorized Admission state and official notices unchanged. |
| Website Professor | `/enrollment/teaching` (`EnrollmentProfessor.vue`) | Shared header, cards, table, buttons, empty/loading/error states. Professor assignment and roster endpoints unchanged. |
| Android Professor | `/enrollment/teaching` through the same role-authorized component | The component no longer branches into Website-only primitives, so Android receives the shared mobile-responsive cards and roster region. Navigation and client authorization remain platform-owned. |
| Electron Registrar | `/campus-notices` (`CampusNoticesView.vue`) | Shared page/card hierarchy, fields, buttons, badges, loading and empty states. Official-source, audience, suspension, acknowledgement, publish/archive APIs unchanged. |
| Electron Admin | `/admin/registrars` (`AccountProvisioningView.vue`) and `/admin-dashboard` | Shared page headers, actions, badges, focus-managed account dialogs, and Admin summary cards/loading. Server-owned role endpoints, password validation, status confirmation, and dashboard API unchanged. |

The Registrar can also access Campus Notices and Professor Management, but the matrix records the primary ownership route rather than claiming every shared route is fully migrated.

## Actual route migration inventory

“Partial” means real shared components or an approved prior design exist, but the whole page is not yet on Design System 5.0. “Pending” means importing tokens alone does not count.

| Platform / role | Actual routes | Status and next migration |
| --- | --- | --- |
| Website Student | `/student-dashboard` | Partial — prior Website dashboard; migrate stats/actions/panels after screenshot comparison. |
| Website Student | `/enrollment/status`, `/enrollment` | Partial — UI 2/4 application workflow; migrate form fields, dialogs, status and actions carefully. Reapplication migration remains unapplied. |
| Website Student | `/enrollment/courses` | **Migrated representative**. |
| Website Student | `/enrollment/courses/:courseId/curriculum`, `/enrollment/steps` | Partial — UI 4 composition; adopt shared card/actions next. |
| Website Student | `/enrollment/subjects`, `/enrollment/schedule`, `/enrollment/cor` | Partial — UI 4 selectors/schedule; preserve official COR body and print. |
| Website Student | `/grading` | Partial — Student published-grades UI; Professor Grading internals remain frozen. |
| Website Student | `/monitoring`, `/monitoring/study-plans`, `/document-requests`, `/settings` | Partial/Pending — preserve AI Help, claim codes, and account-scoped appearance while migrating common fields/states. |
| Website Guest | `/guest-dashboard` | **Migrated representative**. |
| Website Guest | `/admission`, `/admission/result`, `/admission/recommendation` | Partial — existing Admission CSS/workflow; migrate cards/forms/status without changing rules. |
| Website Guest | `/admission/exam` | Pending presentation migration; security contract and Website-only guard take precedence. |
| Website Professor | `/professor-dashboard` | Partial — prior Website dashboard. |
| Website/Android Professor | `/enrollment/teaching` | **Migrated representative**. |
| Website Professor | `/grading/submission`, `/monitoring`, `/monitoring/adviser-alerts`, `/professor/settings` | Partial/Pending; preserve submission and adviser authorization. |
| Website Professor | `/grading` | Frozen; no Design System 5.0 internal change. |
| Electron Registrar | `/registrar-dashboard` | Partial — Desktop UI 3.0 dashboard content. |
| Electron Registrar/Admin | `/campus-notices` | **Migrated representative**. |
| Electron Registrar | `/registrar/professors` | Partial — shared header/dialog/badges via `AccountProvisioningView`; remaining filter/table fields retain compatibility CSS. |
| Electron Admin | `/admin/registrars` | **Migrated representative (partial page coverage)** — shared header/actions/badge/dialog; remaining form/table CSS is compatibility work. |
| Electron Admin | `/admin-dashboard` | Partial representative — shared header, loading, and summary cards; charts and activity panels remain module-specific. |
| Electron Registrar/Admin | `/enrollment/applications`, `/enrollment/periods` | UI 4.0 structurally redesigned; future pass should replace its local UI4 control classes with shared primitives after visual approval. |
| Electron Registrar/Admin | `/enrollment/sections`, `/enrollment/subject-management`, `/enrollment/scheduling`, `/enrollment/records` | Pending per UI4 backlog. |
| Electron Registrar/Admin | Admission routes under `/registrar/admissions*` and `/admin/admissions*` | Pending/Partial; preserve exam publication and question security. |
| Electron Registrar/Admin | `/registrar/document-types`, `/registrar/document-requests`, `/registrar/appointments`, `/registrar/document-requests/history` | Pending/Partial; migrate tables/toolbars/dialogs page by page. |
| Electron Registrar/Admin | `/student-management`, reports, physical records, student detail/documents | Partial Desktop UI 3.0; migrate real content workspaces and keep physical-document workflows. |
| Electron Registrar/Admin | `/grading`, `/grading/student-history`, Monitoring routes, `/registrar/settings` | Pending/Partial; preserve grading handoff and authorization. |
| Android Student | Dashboard, Enrollment status/subjects/schedule/COR, published grades, Monitoring, Document Requests, Settings routes allowed by access control | Pending except inherited tokens; mobile cards/lists must be migrated without desktop table assumptions. |
| Android Guest | Guest dashboard, Admission/status/result/recommendation routes allowed by access control | Pending; entrance-exam taking remains prohibited. |
| Android Professor | Dashboard, Teaching Assignments, Grading/Monitoring routes allowed by access control | Teaching Assignments migrated; remaining routes pending. |

Public `/login`, `/terms`, and `/privacy` remain on their approved public/auth presentations and are not counted as authenticated module migrations.

## Adoption rules

1. New or redesigned module interiors should import shared primitives first and add module CSS only for workflow-specific layout.
2. Do not globally style raw elements; legacy pages migrate deliberately.
3. Keep shells, safe areas, Electron rail/panel, Website navigation, and Android back/touch behavior platform-specific.
4. Use `CdmTable` only when a table is semantically appropriate; Android pages should prefer card/list compositions when width is constrained.
5. Keep official print content outside interactive visual migrations.
6. Remove old CSS only after repository search and rendered regression confirm no consumer remains.

## Files changed in this phase

Shared foundation:

- `CDM_Frontend/src/design-system/tokens.css`
- `CDM_Frontend/src/design-system/CdmButton.vue`
- `CDM_Frontend/src/design-system/CdmCard.vue`
- `CDM_Frontend/src/design-system/CdmInput.vue`
- `CDM_Frontend/src/design-system/CdmSelect.vue`
- `CDM_Frontend/src/design-system/CdmTextarea.vue`
- `CDM_Frontend/src/design-system/CdmBadge.vue`
- `CDM_Frontend/src/design-system/CdmTable.vue`
- `CDM_Frontend/src/design-system/CdmDialog.vue`
- `CDM_Frontend/src/design-system/CdmPageHeader.vue`
- `CDM_Frontend/src/design-system/CdmEmptyState.vue`
- `CDM_Frontend/src/design-system/CdmLoadingState.vue`
- `CDM_Frontend/src/main.js`

Representative module migrations:

- `CDM_Frontend/src/modules/enrollment/EnrollmentCourses.vue`
- `CDM_Frontend/src/modules/enrollment/EnrollmentProfessor.vue`
- `CDM_Frontend/src/modules/dashboard/GuestWebsiteDashboard.vue`
- `CDM_Frontend/src/modules/dashboard/AdminDashboardView.vue`
- `CDM_Frontend/src/modules/campus-notices/CampusNoticesView.vue`
- `CDM_Frontend/src/modules/account-management/AccountProvisioningView.vue`

Regression coverage and documentation:

- `CDM_Frontend/test/unifiedDesignSystemFive.test.js`
- `CDM_Frontend/test/enrollmentAcademic.test.js`
- `CDM_Frontend/test/accountProvisioning.test.js`
- `CDM_Frontend/test/websiteSidebarStepOneOne.test.js`
- `CDM_Frontend/test/websiteUiTwoStepTwo.test.js`
- `docs/CDM_UNIFIED_DESIGN_SYSTEM_5.md`

Some listed paths were already untracked in the preserved working tree before this phase. They remain untracked; no Git staging, stash, or history operation was performed.

## Validation and visual acceptance

Validation completed on 30 October 2026:

- Focused migrated-module regression set: 5 test files passed.
- Design-system component contract: 1 test file passed.
- Full frontend suite: 79 test files passed, 0 failed.
- Website production build: passed with the inert compile-time URL `https://build.invalid/api`.
- Android mobile-development build: passed.
- Electron production renderer build: passed with the inert compile-time URL `https://build.invalid/api`.
- `git diff --check`: passed.

The suite emits its pre-existing CommonJS test warning that `import.meta` is unavailable in CJS while testing `clientPlatform.js`; it does not fail tests or production builds. No Laravel or database command was run because APIs and business rules were not changed.

Rendered screenshot capture was unavailable: no Chromium, Chrome, Playwright executable, or GUI browser is installed in the validation environment. Therefore before/after comparison and screenshot-level visual acceptance remain pending. Manual review must cover Light/Dark/System; Website at 1440, 1024, 768, 390, and 320px; Electron at 1366×768, 1600×900, 1920×1080 and narrow resize; Android small/large phones. Inspect button/input/card/table/dialog consistency, focus and Escape, mobile overflow, touch targets, official content, and role/platform isolation. Passing tests do not substitute for this review.

## Recovery and safety

Pre-change checkpoint: `/tmp/cdm-design-system5-prechange.2F1oGw`. SHA-256 `tracked.patch`: `fc3ed7b72640476300f467da0ab3fdfe80dbef1401304abfc3905d1091558088`; `untracked.tar.gz`: `c10f8cafde82eb72b764c38afc5613dd3d2c3405863db9586a3e2c12aafe5012` (175 paths, excluding prior `.local-recovery` archives). Existing stashes and checkpoints were preserved. No commit, push, merge, reset, clean, stash, deploy, migration, seeder, `.env`, or MariaDB operation is authorized or performed.
