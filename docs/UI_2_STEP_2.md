# CDM Portal UI 2.0 — Step 2

## Scope and safety

Step 2 completes the website academic-module presentation on local `dev`. It preserves the Step 1 navigation rail, persistent module panel, contextual top bar, fixed-size route stage, 150 ms opacity-only transition, and CDM Buddy placement. No backend rule, database schema, migration, deployment, cloud resource, or Git history was changed.

Before implementation, the existing tracked and untracked work was copied to `/tmp/cdm-ui2-step2-prechange.e90pNK`:

- `tracked-working-tree.patch` — SHA-256 `08f1299400bb652e7d74854897141ad3fc891a0ba8b82faea8e6635a5baa22a0`
- `untracked-files.tar.gz` — SHA-256 `c87a797cd72e056183d692cde92d11fd1c67f0b75a8679c545400318100c3b8a` (46 files)

The existing Git stashes and recovery branches were left untouched.

## Website route and module inventory

| Audience | Existing website routes | View/workflow | Existing behavior retained |
| --- | --- | --- | --- |
| Guest | `/guest-dashboard` | Guest dashboard | Step 1 dashboard and authorized notices |
| Guest | `/admission`, `/admission/exam`, `/admission/result`, `/admission/recommendation` | Admission journey | Application identity, server validation, uploads, examination session/save/submit safeguards, published result and recommendation visibility |
| Eligible Guest | `/enrollment/status` | Admission-to-Enrollment handoff | Server-authoritative eligibility and no premature Student identity |
| Student | `/student-dashboard` | Student dashboard | Step 1 dashboard, notices, and Buddy |
| Student | `/admission` and permitted Admission child routes | Admission history/status | Existing role guards and read-only lifecycle behavior |
| Student | `/enrollment/status` | Enrollment application | Eligibility, periods, statuses, classification, requirements, draft/save/submit/review state |
| Student | `/enrollment/subjects`, `/enrollment/schedule`, `/enrollment/cor` | Subjects, schedule, COR | Finalized records only; official COR screen/print content unchanged |
| Student | `/grading` | Published grades | Published-only visibility, GWA/completion rules, export, Professor messaging |
| Student | `/monitoring`, `/monitoring/study-plans` | Monitoring, warnings, plans, notices, AI Help | Published evidence remains distinct from informational AI guidance |
| Student | `/document-requests` | Document services | Request types, appointments, timelines, release states, claim-code workflow, access controls |
| Student | `/settings` | Student settings | Profile, account, document requirements, preferences, appearance, and security APIs |
| Professor | `/professor-dashboard` | Professor dashboard | Step 1 dashboard |
| Professor | `/enrollment`, `/enrollment/teaching` | Teaching assignments, schedules, rosters | Account-scoped verified sections and roster pagination |
| Professor | `/grading` | Class grading workspace | Assessment categories, bulk entry, scores, calculations, deadlines, readiness, draft and submission state |
| Professor | `/monitoring`, `/monitoring/adviser-alerts`, `/monitoring/study-plans` | Academic monitoring | Existing evidence, alerts, plan permissions, and AI Help authorization |

The audit found no Guest Settings, Professor Settings, Student-facing Student Records route, or separate Class Schedule module. None was invented. Staff Student Records and physical-record routes remain Electron-only and were not redesigned.

The academic views are shared source files with Android and/or Electron. Their new structures are gated by `clientPlatform === 'web'`; the existing markup remains in `v-else` branches for non-web clients. Existing API services, Pinia state, route guards, forms, validation, dialogs, upload/download logic, calculations, and permission checks remain the source of truth.

## Component architecture

The website presentation now uses focused primitives in `CDM_Frontend/src/components/website`:

- `WebsiteModuleHeader` — consistent module identity, description, metadata, and actions.
- `WebsiteSectionPanel` — titled task group with optional actions/footer.
- `WebsiteDataTable` — labelled, keyboard-focusable contained scrolling and sticky headers.
- `WebsiteFilterToolbar` — responsive filter form with preserved submit events.
- `WebsiteStatusBadge` — text-plus-color status treatment exposed as an accessible status.
- `WebsiteStepper` — responsive current/completed workflow semantics.
- `WebsiteEmptyState` and `WebsiteLoadingState` — consistent non-destructive feedback.
- `WebsiteActionBar` — responsive groups for related view and task actions.

Existing specialized components were retained where they are safer and more capable than a new abstraction: `EnrollmentDialog`, `AdmissionDialog`, `DocumentRequestDialog`, document-request headers/status/timeline components, grade dialogs, and the protected examination interface. This avoids replacing focus management and workflow-specific safeguards merely for component uniformity.

`website-academic-modules.css` is imported only by `WebsiteAppShell.vue`. Every selector is scoped below `.website-ui-2-shell`, includes narrow-width behavior down to 320 px, and respects reduced motion and the existing theme tokens.

## Structural improvements

- Enrollment: a module header, prominent verified status, accessible status badges, guided four-step draft workflow, clearer application history, and explicit loading/empty states.
- Subjects and schedule: term-first record selection, contained subject table with total units, schedule cards/timetable/list switching, mobile agenda behavior, and no generated course data.
- COR: record selection is integrated into the new workspace; the official COR component and print behavior were not altered.
- Grades: task header with export action, academic-term toolbar, readable result summary, contained sticky table, published-only empty state, and unchanged release policy.
- Monitoring and AI Help: a shared module header and clear refresh action wrap the existing role-aware tabs for warnings, evidence, plans, notices, and AI Help.
- Document Requests: the already task-oriented request, appointment, released-document, history, status, timeline, dialog, and mobile-table system was retained and aligned through the website design tokens instead of being rewritten.
- Admission: a guided applicant header and semantic progress stepper frame the existing application, examination, result, and recommendation flow. Active exam persistence, submission confirmation, upload feedback, and server validation remain unchanged.
- Professor assignments: verified class cards, schedule context, an explicit empty state, and a contained roster table.
- Professor grading: a focused class context header and loading state augment the existing stable grade-entry grid, bulk assessment editor, readiness checks, deadlines, final calculations, and submission actions.
- Settings: a website module header aligns the existing Student settings workspace without adding unsupported sections.

## Files added for Step 2

- `CDM_Frontend/src/assets/styles/website-academic-modules.css`
- `CDM_Frontend/src/components/website/WebsiteActionBar.vue`
- `CDM_Frontend/src/components/website/WebsiteDataTable.vue`
- `CDM_Frontend/src/components/website/WebsiteEmptyState.vue`
- `CDM_Frontend/src/components/website/WebsiteFilterToolbar.vue`
- `CDM_Frontend/src/components/website/WebsiteLoadingState.vue`
- `CDM_Frontend/src/components/website/WebsiteModuleHeader.vue`
- `CDM_Frontend/src/components/website/WebsiteSectionPanel.vue`
- `CDM_Frontend/src/components/website/WebsiteStatusBadge.vue`
- `CDM_Frontend/src/components/website/WebsiteStepper.vue`
- `CDM_Frontend/test/websiteUiTwoStepTwo.test.js`
- `docs/UI_2_STEP_2.md`

## Files modified for Step 2

- `CDM_Frontend/src/layouts/WebsiteAppShell.vue`
- `CDM_Frontend/src/modules/admission/AdmissionView.vue`
- `CDM_Frontend/src/modules/enrollment/EnrollmentAcademicStudent.vue`
- `CDM_Frontend/src/modules/enrollment/EnrollmentLoad.vue`
- `CDM_Frontend/src/modules/enrollment/EnrollmentProfessor.vue`
- `CDM_Frontend/src/modules/enrollment/EnrollmentSchedule.vue`
- `CDM_Frontend/src/modules/enrollment/EnrollmentStudent.vue`
- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/StudentGradesView.vue`
- `CDM_Frontend/src/modules/monitoring/MonitoringView.vue`
- `CDM_Frontend/src/views/SettingsView.vue`
- `CDM_Frontend/test/admissionIntegration.test.js`
- `CDM_Frontend/test/enrollmentAcademic.test.js`
- `CDM_Frontend/test/enrollmentFoundation.test.js`

The three existing integration harnesses now compile the shared website primitives instead of replacing them with null renderers. Their workflow assertions were not weakened.

## Functionality and isolation explicitly preserved

- Admission and examination validation, autosave/session revision, countdown/submission safeguards, and published-result rules.
- Enrollment eligibility, Admission dependencies, academic terms, configurable requirements, classifications, statuses, server validation, and controlled subject identifiers.
- COR content, totals, print behavior, and finalized-record source.
- Published-grade restrictions, assessment calculations, grading scale, bulk entry, deadlines, readiness, submission/review/release permissions.
- Monitoring evidence authorization and the distinction between official evidence and AI guidance.
- Document type, appointment, claim-code email, request transition, and access-control behavior.
- Student profile/security/preferences, file upload/download paths, and validation feedback.
- Step 1 route-stage geometry and opacity-only transition; no additional route transform was introduced.
- CDM Buddy remains outside the route transition and retains page greetings, account-scoped mute, drag/docking, notice acknowledgement, audience restrictions, and Settings exclusion.
- `VITE_CDM_CLIENT=mobile` and `VITE_CDM_CLIENT=desktop` continue through the legacy shell/presentation path. Admin and Registrar remain desktop-only; Student, Guest, and Professor remain web/Android-authorized according to the existing guard.
- Event Management was not restored.

## Automated validation

Record final command results here after the final validation pass:

| Check | Result |
| --- | --- |
| Focused Step 2 tests | PASS — 6/6 |
| Admission integration tests | PASS — 29/29 |
| Enrollment academic tests | PASS — 10/10 |
| Enrollment foundation tests | PASS — 10/10 |
| Full frontend suite | PASS — 45/45 test files |
| Website production build | PASS — Vite production build, 327 modules |
| Android mobile-development build | PASS — Vite `mobile-development`, 327 modules |
| Electron production build | PASS — Vite desktop build plus Linux x64 AppImage (`113M`) |
| `git diff --check` | PASS |

No Laravel interface or backend behavior was changed by Step 2, so no database-backed Laravel suite is required for this presentation-only pass. Automated frontend tests use mocks/in-memory renderers and do not access MariaDB.

The first Electron packaging attempt completed its Vite compile but the sandbox could not resolve `github.com` while fetching the Electron runtime. The approved network retry succeeded and produced `CDM_Frontend/release/CDM Portal-0.1.0.AppImage`. Electron Builder emitted non-blocking metadata recommendations for `desktopName` and Linux category; no product behavior or packaging failure resulted. The final desktop bundle contains no `website-ui-2-shell` or `website-academic-client` selectors.

## Known limitations

- No Chromium/Playwright runtime is available in the local environment, so automated screenshot comparison, visual viewport testing, and real-browser focus traversal were not possible.
- Actual production-like Student, Guest, and Professor records are required to visually verify long names, large class rosters, dense grade sheets, real schedules, upload errors, and all server-generated status combinations.
- The mobile and Electron builds verify compile-time isolation; manual device/desktop inspection is still required to accept unchanged appearance.

## Manual UAT checklist

Use local accounts and local API/database only. Test at 1440, 1024, 768, 390, and 320 px browser widths.

### Student

- Open Enrollment status; verify period/status, eligibility, next action, draft steps, validation, save, confirmation, and review states.
- Open Subjects, Schedule, and COR with finalized data; verify exact subject/unit data, card/timetable/list views, rooms/faculty, contained horizontal scrolling, print, and unchanged official COR.
- Open Grades; verify only published grades appear, term filtering works, incomplete-term messaging is accurate, export works, and Professor messaging opens safely.
- Open Monitoring; verify warnings/evidence, Study Plans, My Notices, and AI Help permissions and labels.
- Create and inspect a Document Request; verify active/history/released filters, appointment states, claim-code instructions/email flow, confirmation focus, and mobile table handling.
- Open Settings; verify profile/avatar, requirements, contact details, preferences, theme, password flow, and that Buddy is absent.

### Guest

- Verify Admission status, application creation, required documents, upload/preview failures, progress, and official next actions.
- Start/resume an entrance examination; verify timing, saved position/answers, refresh recovery, navigation protection, confirmation, final submission, and result publication restrictions.
- Verify only an admitted eligible Guest receives the Enrollment status handoff.
- Confirm Guest has no Settings or invented modules.

### Professor

- Open Teaching Assignments; verify only account-scoped sections, real schedules, rosters, and pagination.
- Open each grading class; verify class/term context does not shift while editing, assessment/category controls, bulk parsing, editable versus read-only states, score saves, calculations, deadlines, errors, readiness, and submit/resubmit flow.
- Verify Monitoring adviser/study-plan access remains role-authorized.

### Cross-cutting

- Navigate repeatedly Dashboard → Enrollment → Subjects → Schedule → Document Requests → Monitoring → Dashboard; verify no width wiggle, temporary scrollbar, double motion, or secondary-panel churn.
- Verify keyboard focus visibility, logical tab order, labelled scroll regions, dialog focus restoration, Escape behavior where permitted, and 44 px-class touch targets on narrow widths.
- Verify light, dark, and system themes; ensure status meaning is readable without color alone.
- Verify Buddy greetings, mute/unmute persistence per account, manual opening while muted, dragging, docking, notice state, acknowledgement actions, form/dialog suppression, and Settings exclusion.
- Launch Android and Electron artifacts and compare their navigation, dashboards, academic pages, dialogs, and spacing to the accepted pre-Step-2 presentation.

## Visual acceptance criteria

- All website academic modules read as one forest-green/emerald/gold/slate application with consistent hierarchy, spacing, controls, statuses, and feedback.
- The redesign is structural: task headers, panels, progress, filters, summaries, contained data regions, and responsive compositions replace isolated legacy page-header/card stacking.
- Tables remain readable at desktop sizes and use contained scrolling or mobile cards/agenda views without page-level horizontal overflow.
- Editable grade controls are visibly distinct from published/read-only values and remain stable during entry.
- Critical exam, enrollment, upload, request, and grading actions remain visible, labelled, and protected from accidental dismissal.
- No route transition, Buddy state change, or secondary navigation action repositions the main workspace.
