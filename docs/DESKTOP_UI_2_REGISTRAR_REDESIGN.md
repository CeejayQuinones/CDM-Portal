# Desktop UI 2 — Registrar and Admin Interface

## Scope and safety boundary

This phase refines the authenticated Electron presentation for Registrar Staff and Admin. The compile-time desktop client (`VITE_CDM_CLIENT=desktop`) receives the `cdm-desktop-client` root marker and `desktop-ui-two-shell`; Website continues to use `WebsiteAppShell`, and Capacitor continues to use the non-desktop shared presentation. No Laravel code, endpoint contract, database schema, permission, academic rule, or Electron security setting was changed.

Recovery checkpoint: `.local-recovery/desktop-ui2-registrar-prechange-20261028/`. Its tracked patch and untracked archive were verified before implementation. Existing stashes were not modified.

## Audit findings

- The existing Electron shell used the shared fixed sidebar and navbar, but did not enable the already-supported collapse behavior. At narrow resizable-window widths the content margin disappeared without exposing a reliable drawer trigger.
- The top bar emphasized the institution label and page name but did not provide a concise staff account context.
- Registrar Dashboard already used real summary, recent-activity, appointment, Student Records, Physical Records, and Document Request data. It did not need invented widgets or new endpoints.
- Student Records already provides local/remote search, advanced filters, saved presets, visible-column controls, bulk actions, pagination, record attention queues, and explicit empty/loading/error states.
- Enrollment staff routes already cover real Applications, Periods, Sections, Subjects, Scheduling, Records, COR review, and Professor assignments. Existing workflow rules remain authoritative.
- Actual desktop navigation also exposes Admission administration, Grade Periods/Student Grade History, Academic Monitoring, Document Types/Requests/Appointments/History, Campus Notices, account management according to role, and Settings.
- Shared module styles had inconsistent surface, border, input, table-header, and dialog presentation. Teleported dialogs require a root client marker because they render outside the layout shell.
- Light, Dark, and System preferences already use account-scoped appearance storage for Registrar and Admin; the redesign extends that foundation instead of adding another preference store.

## Implementation

### Desktop shell and navigation

- Added an Electron-only semantic token layer with neutral canvas/surfaces, forest-green actions, restrained academic-gold accents, compact borders, and dark/system variants.
- Enabled the existing sidebar collapse interaction for wide Electron windows. Its preference is stored per account under `cdm-desktop-sidebar:<account>`.
- Added narrow-window drawer behavior, focus restoration, Escape handling, backdrop dismissal, and inert background content.
- Preserved the real role-filtered navigation model and existing route guards. No unsupported page or action was added.
- Retained a stable content region while the rail changes width, and limited desktop route motion to opacity.

### Contextual top bar

- Added page and role context plus a compact signed-in identity block for Electron.
- Kept offline-demo controls when that existing mode is active.
- Did not add a fake notification bell, fabricated counts, or a new account menu.

### Registrar/Admin workspaces

- Applied shared desktop surfaces to existing dashboards, record workspaces, enrollment cards, filters, settings panels, notices, and document-request panels.
- Standardized form focus treatment, primary/secondary action hierarchy, table containment, compact sticky table headings, row hover feedback, status/error surfaces, and teleported dialog theming.
- Kept existing loading, empty, validation, confirmation, and error behavior. No action was relabeled to imply behavior that the backend does not provide.
- Preserved official COR/print behavior and all existing record, approval, grading, and authorization logic.

### Existing contracts and permissions

- Dashboard presentation continues to consume `getRegistrarDashboard()` and its existing summary, activity, and appointment response. No additional request is issued by the shell.
- Student Records continues to use its existing Student Management services for search, productivity queues, completeness, bulk options, details, documents, and physical locations.
- Enrollment staff views continue to use the existing enrollment service contracts for applications, periods, academic configuration, schedules, records, COR, and assignments.
- Admission, grading review/history, document requests, campus notices, account provisioning, monitoring, and Settings retain their existing services and router role metadata.
- Registrar Staff and Admin still depend on the desktop-only role policy in `clientPlatform.js`; the redesign does not make either role available to Website or Android.

### Admin-specific considerations

Admin receives the same desktop shell and semantic system but only sees destinations granted by existing role filters. Account provisioning, admission configuration, campus notices, academic configuration, monitoring, grading history, and Settings retain their current controls. Registrar-only physical-record and document-processing behavior is not broadened to Admin.

### Remaining screens awaiting visual review

The shell-level conventions safely cover the existing desktop routes, but complex module-specific screens were not structurally rewritten. Admission question/exam administration, large scheduling grids, grade-history workspaces, Monitoring detail panels, document-request queues, and account provisioning still require screenshot-based review with representative data. Any deeper restructuring should be handled as a separate, workflow-specific pass rather than inferred from empty/local test states.

## Files added

- `CDM_Frontend/src/assets/styles/desktop-ui-2.css`
- `CDM_Frontend/test/desktopUiTwoRegistrar.test.js`
- `docs/DESKTOP_UI_2_REGISTRAR_REDESIGN.md`

## Files modified

- `CDM_Frontend/src/main.js`
- `CDM_Frontend/src/layouts/DashboardLayout.vue`
- `CDM_Frontend/src/components/Sidebar.vue`
- `CDM_Frontend/src/components/Navbar.vue`

## Platform isolation

- Website: still branches to `WebsiteAppShell`; desktop selectors require `html.cdm-desktop-client` or `.desktop-ui-two-shell`.
- Android: `clientPlatform === 'mobile'` never receives the desktop marker or shell class.
- Electron: receives the marker before Vue mounts, which also covers teleported overlays without a theme flash.
- The Professor Grading and Student Enrollment implementations were not edited.

## Validation results

- Desktop UI focused and isolation regression set: 4 test files passed.
- Full frontend suite: 73 test files passed, 0 failed, in 11.18 seconds. This includes platform access, Registrar theme/settings, Student Records, Enrollment, Professor Grading, CDM Buddy, and website navigation tests. The existing esbuild `import.meta` CommonJS test warning remains non-fatal.
- Website production build: passed; 357 modules transformed in 12.72 seconds. An inert `https://build.invalid/api` build-time URL satisfied the production HTTPS guard; no environment file was changed and no request was sent.
- Android mobile-development build: passed; 351 modules transformed in 10.85 seconds.
- Electron desktop-development renderer build: passed; 351 modules transformed in 11.01 seconds.
- `git diff --check`: passed with no whitespace errors.

No browser/Electron screenshots were captured in this environment. Visual acceptance remains manual.

## Manual UAT checklist

Test Registrar Staff and Admin separately at 1366×768, 1920×1080, and a narrow resizable Electron window.

1. Sign in and confirm only authorized navigation destinations appear; Event Management must not appear.
2. Collapse and expand the rail, reload, and confirm the preference restores only for the same account.
3. Resize below 860px, open the drawer from the top bar, tab through it, press Escape, and confirm focus returns to the menu button.
4. Review dashboard summary, quick actions, alerts, recent activity, appointments, loading, empty, and failed-request states using real local data.
5. Exercise Student Records search, advanced filters, presets, visible columns, selection, bulk confirmation, pagination, Student detail, Documents, Physical Records, and reports.
6. Exercise Enrollment Applications, Periods, Sections, Subjects, Scheduling, Records, COR review/print, and Professor assignments. Confirm validation and authorization behavior are unchanged.
7. Review Admission administration, Grade History/Periods, Academic Monitoring, Document Types/Requests/Appointments/History, Campus Notices, account management, and Settings according to the signed-in role.
8. Open teleported dialogs and confirmations; verify focus, Escape behavior where supported, background treatment, long-content scrolling, and destructive-action clarity.
9. Switch Light, Dark, and System, reload, and check shell, tables, inputs, dialogs, badges, and focus indicators.
10. Confirm COR and other official print outputs retain their light document appearance.
11. Launch Website and Android builds and confirm their existing navigation geometry, Student/Guest/Professor UI, CDM Buddy, Enrollment Phase 1.2, and Professor Grading presentation are visually unchanged.

## Known limitations

- Final visual sign-off requires a packaged Electron session with representative local accounts and records.
- The top bar intentionally exposes no notification control because no verified shared notification action was identified for this shell.
- Existing module-specific responsive behavior remains authoritative where a workflow has specialized dense tables.
