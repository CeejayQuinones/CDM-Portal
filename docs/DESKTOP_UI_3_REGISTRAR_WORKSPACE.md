# Desktop UI 3 — Registrar Workspace Architecture

## Previous design shortcomings

Desktop UI 2 improved visual consistency but retained the original application architecture: a 260px full-height text sidebar, nested expandable menus, a generic top bar, repeated page headings, and a dashboard composed from four large metric cards, Quick Actions, Record Alerts, Recent Activity, and Appointments. That created redundant navigation and used too much horizontal and vertical space for framing rather than Registrar work.

Student Records and Enrollment Records also remained stacks of independently boxed sections. Their existing functions were strong—search, filters, presets, bulk controls, pagination, COR review, productivity queues—but the presentation did not read as one continuous administrative workspace.

## New application architecture

Electron now branches from `DashboardLayout.vue` into a dedicated `DesktopAppShell.vue`. It no longer renders the shared `Sidebar.vue` and `Navbar.vue` used by the legacy non-website shell.

The desktop grid contains:

1. A fixed 72px primary rail with the approved CDM seal, verified role-derived modules, icon buttons, tooltips, focus states, and a separate Settings position.
2. A 224px contextual module panel only when the active module has multiple real destinations.
3. A compact 64px contextual header with module/page context, authenticated staff identity, and sign-out.
4. A locally scrolling work stage that receives the real router view.

Below 1040px the contextual panel becomes an overlay opened from the header. The primary rail and workspace remain stable. Escape closes the overlay, route changes close it automatically, and native links/buttons retain keyboard behavior.

## Route-to-module mapping

The rail is not a second hardcoded permission list. `desktopModulesFromMenu()` transforms the already role-filtered navigation store into desktop modules and destinations.

| Existing navigation group | Desktop module | Representative real destinations |
| --- | --- | --- |
| Registrar/Admin Dashboard | Overview | Authorized dashboard route |
| Student Management | Students | Students, Physical Records, Reports |
| Enrollment | Enrollment | Applications, Periods, Sections, Subjects, Scheduling, Records |
| Grading | Grading | Grade Periods, Student Grade History where authorized |
| Document Requests | Documents | Types, Requests, Appointments, History |
| Admission | Admission | Existing applicant, result, history, program, exam, and question routes according to role |
| Academic Monitoring | Academic Monitoring | Existing monitoring route |
| Account Management | Account Management | Registrar or Professor management according to role |
| Campus Notices | Campus Notices | Existing notices route |
| Settings | Settings | Existing role-authorized desktop Settings route |

Single-destination modules navigate immediately and do not render a secondary panel. Multi-destination modules use the panel and preserve nested-route active state. No route, notification, count, permission, or administrative capability was invented.

## Dashboard information hierarchy

The Registrar dashboard template was reconstructed rather than restyled.

- Work summary is one compact four-column strip using the existing `total_students`, `pending_document_requests`, `todays_appointments`, and `students_without_record_location` response fields.
- Priority work queue is the dominant region. It uses verified pending document requests, missing-document records, records without a physical location, and today's appointment count. Each row links to an existing authorized destination.
- Operational context is a compact side column containing today's real appointments and existing recent activity.
- The former Quick Actions and Record Alerts card grids are no longer rendered.
- Loading, empty, failure, retry, exact/relative timestamp, and appointment status behavior remain tied to the existing dashboard service.

## Record workspace changes

Student Records now has an explicit `student-records-workspace` boundary. On Electron:

- Page-specific search leads the workspace instead of appearing as a decorative card.
- Productivity information is compressed into a denser operational row.
- Filters, saved presets, visible columns, selection, bulk controls, table, pagination, keyboard shortcut, and server interactions are preserved.
- The result table has a contained height and local scroll at desktop resolutions.
- Existing Student detail and document routes remain the dedicated full-record experience. A new side preview was deliberately not introduced because the current list response should not be assumed to contain every authorized detail field, and silently fetching records on row focus could expose or load unrelated data.

Enrollment Records retains its existing API contracts and dialog-based COR review. Electron presentation now uses a compact filter toolbar, locally scrolling result table, and stable pagination. Sections and Scheduling inherit the same workspace primitives without changing create/edit/delete, reassignment, conflict, or validation behavior.

## Forms, dialogs, and shared consistency

Desktop UI 3 provides semantic Electron-only styling for compact fields, focus rings, primary/secondary actions, status feedback, dense tables, contained scrolling, and teleported dialogs. Existing module components continue to own validation, submission, confirmation, loading, and error behavior. Official printing removes navigation/header chrome and forces a light document palette.

Complex screens—including Admission exam/question administration, large scheduling grids, grade history, Monitoring details, Document Request processing, and account provisioning—receive the shared shell and primitives but were not structurally rewritten. They require representative-data screenshot review before deeper workflow-specific changes.

## Component structure

- `DesktopAppShell.vue`: Electron workspace grid, route stage, responsive contextual panel.
- `DesktopPrimaryRail.vue`: role-derived module rail and accessible tooltips.
- `DesktopContextPanel.vue`: real child destinations and active state.
- `DesktopContextHeader.vue`: current context, account identity, sign-out, narrow-window module trigger.
- `desktopNavigation.js`: deterministic navigation transformation and route matching.
- `desktop-ui-3.css`: scoped semantic tokens, workspace layout, dense administrative primitives, dark/system/print behavior.

## Desktop-specific design tokens

The desktop stylesheet defines semantic canvas, surface, muted surface, line, ink, muted text, forest-green, emerald, gold, danger, warning, rail width, panel width, and header height tokens. Explicit Dark and System-dark values use the existing account-scoped `data-student-theme` architecture. Selectors require `html.cdm-desktop-client` or `.desktop-workspace-shell`.

## Files added

- `CDM_Frontend/src/layouts/DesktopAppShell.vue`
- `CDM_Frontend/src/components/desktop/DesktopPrimaryRail.vue`
- `CDM_Frontend/src/components/desktop/DesktopContextPanel.vue`
- `CDM_Frontend/src/components/desktop/DesktopContextHeader.vue`
- `CDM_Frontend/src/config/desktopNavigation.js`
- `CDM_Frontend/src/assets/styles/desktop-ui-3.css`
- `CDM_Frontend/test/desktopUiThreeRegistrar.test.js`
- `docs/DESKTOP_UI_3_REGISTRAR_WORKSPACE.md`

## Files modified

- `CDM_Frontend/src/layouts/DashboardLayout.vue`
- `CDM_Frontend/src/modules/registrar-dashboard/RegistrarDashboardView.vue`
- `CDM_Frontend/src/modules/student-management/StudentRecordsView.vue`
- `CDM_Frontend/test/desktopUiTwoRegistrar.test.js` (updated to verify UI 2 is superseded, not active)

## Recovery checkpoint

`.local-recovery/desktop-ui3-registrar-prechange-20261028/` contains the pre-change tracked binary patch, the 146-path untracked archive, branch/HEAD/stash metadata, and verified SHA-256 hashes. The prior Desktop UI 2 checkpoint and all three Git stashes remain untouched.

## Tests and build results

- Focused UI 3, platform, dashboard, records, Enrollment, and Grading regressions: passed (7 test files).
- Full frontend suite: 74 test files passed, 0 failed, in 8.16 seconds. The existing non-fatal esbuild CommonJS `import.meta` warning remains.
- Website production build: passed; 362 modules transformed in 11.66 seconds. An inert `https://build.invalid/api` value was supplied only to satisfy the production build guard; no environment file was changed and no request was made.
- Android mobile-development build: passed; 357 modules transformed in 13.17 seconds.
- Electron desktop-development renderer build: passed; 357 modules transformed in 11.15 seconds.
- `git diff --check`: passed with no whitespace errors.

No Laravel or database command is required because backend contracts were not modified.

## Platform isolation

- Website continues to branch first to `WebsiteAppShell.vue` and does not import Desktop UI 3.
- Electron branches to `DesktopAppShell.vue` only when `clientPlatform === 'desktop'`.
- Android continues through the existing non-web/non-desktop shell. Desktop selectors are inactive without the desktop root marker and workspace class.
- Professor Grading and Enrollment Phase 1.2 business implementations were not changed.
- Electron security configuration was not changed.

## Remaining visual and functional gaps

- Real Electron screenshots were not available during implementation; visual acceptance is pending.
- Student detail remains a dedicated route rather than a speculative master-detail preview.
- Complex academic/admin screens use shared workspace conventions but await separately scoped structural reviews if manual UAT finds workflow-specific problems.
- The rail intentionally shows every actual top-level group granted by the existing navigation policy. Manual UAT should confirm the number remains comfortable for both Registrar and Admin at 768px height with representative permissions.

## Manual Electron UAT checklist

Test Registrar Staff and Admin separately at 1366×768, 1600×900, 1920×1080, and a narrow resized window.

1. Confirm the 72px rail replaces the old text sidebar and that only role-authorized modules appear.
2. Hover and keyboard-focus every rail action; verify readable tooltips and clear active state.
3. Open single-destination modules and confirm no contextual panel appears.
4. Open Enrollment, Students, Documents, Grading, Admission, and account-management groups where authorized; verify only real destinations and correct active state.
5. Resize below 1040px, open the contextual overlay, navigate by keyboard, press Escape, and check that content never overlaps the rail.
6. Verify the header displays the current module/page and correct staff account, and that sign-out completes before redirecting.
7. On the Registrar dashboard, compare all displayed counts, priority rows, appointments, and recent activity with actual local data. Exercise loading, empty, and service-error states.
8. In Student Records, exercise Ctrl/Cmd+K search, listing search, advanced filters, presets, column selection, selection, bulk confirmations, pagination, dedicated detail, documents, Physical Records, and reports.
9. In Enrollment Records, exercise every filter and sort, pagination, COR view and print, missing/invalid records, and dialog focus. Repeat for Applications, Periods, Sections, Subjects, and Scheduling.
10. Exercise Document Requests/Appointments, Admission administration, Grade History/Periods, Monitoring, Campus Notices, account management, and Settings without changing their backend rules.
11. Verify Light, Dark, and System across rail, panel, header, dashboard, records, forms, tables, dialogs, and status feedback.
12. Print a COR or other official document and confirm navigation chrome is absent and the document stays light.
13. Launch Website and Android builds and confirm their existing navigation, dashboards, CDM Buddy, Professor Grading, and Enrollment Phase 1.2 appearance remains unchanged.

Stop for screenshot-based manual review before any further desktop redesign phase.
