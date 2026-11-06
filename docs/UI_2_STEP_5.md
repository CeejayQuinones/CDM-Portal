# CDM Portal UI 2.0 — Step 5 UX refinement

## Scope and baseline

Local `dev` at `bf7a689`, with prior uncommitted UI 2.0, CDM Buddy, academic, and backend work left intact. No Git history or stashes were changed. A verified pre-edit checkpoint of tracked and untracked source is in `.local-recovery/ui2-step5/` (tracked patch SHA256 `48c3146817fc29662ecc2ab09a96df2561e9c9506390de8e159c7d7fad0e2cf0`; untracked archive SHA256 `0f94537b636d32500417ffd37982977415f0c61104927c26ac55a7127812e8e3`). This report covers Step 5 edits only, not the prior dirty worktree.

The source audit covered Student Enrollment, subjects, schedule, grades, Monitoring/AI Help, Document Requests, and Settings; Guest dashboard, Admission, exam/result, and application status; Professor dashboard, assignments, grading, bulk entry, and Settings; and the shared website shell, desktop panel, mobile drawer, themes, and Buddy. Enrollment and Admission already expose next steps and error/loading feedback; Document Requests already exposes status/history. No speculative workflow changes were made there.

## Actual findings and repairs

| Severity | Source evidence before Step 5 | Before → after |
| --- | --- | --- |
| Moderate | Mobile drawer matched only exact `destination.name`; desktop panel used nested `activeRoutes`. The active multi-destination module did not auto-expand when opened. | Nested pages could appear unselected → shared route-name matching now marks the module and destination active, exposes `aria-current="page"`, and expands the current module on drawer open. The desktop panel uses the same route-name helper for both styling and `aria-current`. |
| Moderate | Guest dashboard displayed Entrance examination and Application result quick links unconditionally. | Guests could open unavailable steps → exam link appears only for an authorized eligible/in-progress state; result link appears only after publication. Admission status remains available. A pure availability function has state-based tests. |
| Minor | Professor dashboard duplicated two destinations under four labels. | Quick-action area repeated links → one card per existing teaching, grading/submission, monitoring, and Settings destination. The primary Grading action remains. |
| Moderate | Bulk-assessment row inputs/select had column headers but no row-specific accessible names. | Repeated controls were difficult to distinguish by assistive technology → each row control names its row number and purpose; paste textarea has an accessible name. Assessment calculations and submission logic are untouched. |

Source inspection did not establish rendered defects at 1440, 1024, 768, 390, or 320 pixels. No browser screenshot or automated viewport inspection was available; visual and touch acceptance is pending manual UAT.

## Step 5 files

- `CDM_Frontend/src/config/websiteNavigation.js`: shared route-name active-state helpers.
- `CDM_Frontend/src/components/website/WebsiteMobileNavigation.vue`: current-route highlighting, active-module expansion, and `aria-current`.
- `CDM_Frontend/src/components/website/WebsiteNavigationPanel.vue`: consistent active route and `aria-current`.
- `CDM_Frontend/src/modules/dashboard/dashboardPresentation.js`: pure Guest action-availability helper.
- `CDM_Frontend/src/modules/dashboard/GuestWebsiteDashboard.vue`: verified workflow-dependent quick actions and responsive card grid.
- `CDM_Frontend/src/modules/dashboard/ProfessorWebsiteDashboard.vue`: nonduplicated quick actions and responsive card grid.
- `CDM_Frontend/src/modules/grading/GradingView.vue`: accessible names only for bulk-assessment controls; shared component presentation was not changed.
- `CDM_Frontend/test/websiteNavigationStability.test.js` and new `CDM_Frontend/test/websiteStepFive.test.js`: route, workflow-state, accessibility-markup, and platform-branch regressions.
- `docs/UI_2_STEP_5.md`: this report.

## Accessibility, responsive, and platform boundaries

Mobile drawer links retain 44px minimum height and existing focus trapping; the active destination is now exposed semantically. No global animation, transform, route transition, theme token, Buddy, authorization, API, Laravel, or database code was changed for Step 5. Website dashboard edits sit behind the existing `clientPlatform === 'web'` branches. The shared Grading component received only `aria-label` attributes, without visual styling or workflow changes. The existing 150ms opacity-only website route transition remains intact. Android and Electron renderer builds confirm compilation, but do not substitute for device-level visual review. Light/Dark/System and Buddy need the manual visual check below.

## Validation

- Full frontend suite: **48/48 test files passed** after all Step 5 changes. The existing `import.meta`/CommonJS warning in one test remained non-fatal.
- Website production build: **passed** using build-time-only inert `https://api.example.invalid/api`.
- Android mobile-development build: **passed**; no Capacitor sync or package installation.
- Electron desktop renderer build: **passed** with build-time-only inert `https://api.example.invalid/api`; no packaging/deployment.
- `git diff --check`: **passed** after Step 5 changes.
- No Laravel or database commands were run; backend business rules were not touched for Step 5.

## Manual UAT still required

1. At 1440, 1024, 768, 390, and 320 pixels, open Student, Guest, and Professor website dashboards and key modules in Light, Dark, and System. Check no horizontal page overflow, covered buttons, contrast failures, or layout shifts.
2. On a narrow screen, enter nested Admission and Enrollment destinations, open the drawer, and verify the current section is expanded and its destination highlighted. Tab through links, use Escape, and confirm focus returns to the menu trigger.
3. With Guest accounts representing not started, eligible, exam in progress, completed, and published-result states, verify that only authorized quick actions appear and the primary next action agrees. Complete an Admission form/exam without altering its workflow.
4. As Professor, follow Assigned classes, Grading/submission, Monitoring, and Settings; enter several bulk-assessment rows with keyboard and a screen reader, verifying each field announces its row and purpose. Check draft/submission errors and success feedback.
5. Check Enrollment, Student Grades, Monitoring/AI Help, Document Request status/claim-code flows, forms and dialogs. Confirm search/filter states, keyboard focus, responsive tables, and no regression in academic data or permissions.
6. Move Buddy near navigation and critical actions, open/mute/dock it, and navigate. Check notice availability/acknowledgement and Settings exclusion. Repeat in Light, Dark, and System.
7. Smoke-test installed Android and Electron presentation. Build success alone does not prove visual isolation.

No screenshot-based before/after comparison can be supplied from this environment. Remaining defects, if any, should be logged from the manual matrix before accepting Step 5.
