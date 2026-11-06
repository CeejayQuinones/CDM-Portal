# CDM Portal UI 2.0 — Step 7: Website page interiors

Status: local implementation for visual review on `dev`. No commit, push, deployment, migration, or database access was performed.

## Recovery and scope

Before editing, the existing uncommitted work was preserved in verified source and backend archives at `/tmp/cdm-step7-prechange.dwIBgz`. The existing stashes and `.local-recovery/` were not changed. Step 7 changes are limited to website page interiors and shared website-only components. The 72px rail, secondary panel, topbar, connected selection, jelly animation, route transition, and overall shell geometry were not edited.

## Findings and design principles

- The three website dashboards previously placed a full-width overview above uniform metric tiles. The hierarchy made the real next task and term context less prominent than the repeated cards.
- Enrollment had the correct application actions, but no concise visual callout identifying the next action when returning to its landing state.
- Admission already had verified status, a stepper, and a next-action link, but these appeared as a long vertical stack on wide screens.
- Academic records, schedules, grading, monitoring, requests, and Settings already had functional content. Their interior surfaces varied in emphasis and were comparatively flat; dense grading tables particularly needed stronger editable-cell and keyboard-focus distinction.

The content language uses existing forest, emerald, gold, and semantic status tokens. Large accents are reserved for authenticated overview and real next-action data. Work tables stay compact. No fictional metrics, deadlines, subjects, grades, notices, or risk determinations were introduced.

## Components and module changes

The existing `WebsitePageHeading` and `WebsiteModuleHeader` now use more readable headings and a restrained gold marker instead of heavily tracked uppercase labels. `WebsiteStatCard` uses a semantic accent and one gold-priority treatment. `WebsiteQuickAction` has a clearer action affordance, keyboard focus, and 140ms hover/press feedback. These remain reusable components rather than a second design system.

| Area | Before | After |
| --- | --- | --- |
| Student, Guest, Professor dashboards | Overview banner followed by uniform statistics | Two-part responsive command grid: account/term context beside verified metrics; real actions and notices remain below |
| Enrollment | Action button detached from status | Data-driven next-action callout using the existing application/eligibility state; status, subject load, and record rows gain hierarchy |
| Subjects and schedule | Flat cards and schedule rows | Stronger subject/title, verified time/room, and term emphasis; existing card, timetable, list, and print behavior retained |
| Guest Admission | Status, stepper, next action, and details in a long column | Verified identity and progress span the workspace; next action and details sit side by side on wide screens and stack on mobile |
| Student grades and Professor grading | Similar treatment for editable and read-only cells | Emphasized summaries, segmented tabs, sticky headers, editable-cell tint, disabled contrast, and keyboard-focused row feedback |
| Academic Monitoring and AI Help | Flat tab and summary presentation | Segmented navigation and semantic risk-summary emphasis; published evidence and AI guidance remain separate |
| Document Requests | Equal summary tiles | Active-request emphasis and responsive bento summary; existing filters, timeline, appointments, claim-code, and state transitions preserved |
| Student and Professor Settings | Functional but plain content areas | Clearer profile, official-information, and appearance card grouping; unsupported Professor edits remain unavailable |

Admission exam timers, active answers, draft persistence, and safeguards were deliberately not animated or rewritten. Official COR and other print content retain their existing print overrides.

## Files changed for Step 7

- `CDM_Frontend/src/modules/dashboard/{Student,Guest,Professor}WebsiteDashboard.vue`
- `CDM_Frontend/src/modules/dashboard/websiteDashboard.css`
- `CDM_Frontend/src/modules/enrollment/EnrollmentStudent.vue`
- `CDM_Frontend/src/modules/admission/AdmissionView.vue`
- `CDM_Frontend/src/components/website/{WebsitePageHeading,WebsiteModuleHeader,WebsiteStatCard,WebsiteQuickAction}.vue`
- `CDM_Frontend/src/assets/styles/website-academic-modules.css`
- `CDM_Frontend/test/websiteStepSeven.test.js`
- `docs/UI_2_STEP_7.md`

The changed shared components are consumed by the website shell. The content CSS is loaded only by that shell and all broad rules require `html.website-ui-2-client .website-ui-2-shell`. Android and Electron continue to use their existing presentation. No new dependency, image, API request, watcher, backend rule, or schema change was introduced.

## Motion, theme, responsiveness, and performance

Only content controls gain short hover/press, border, or shadow transitions. No page-wide transforms or new route animations were added. `prefers-reduced-motion` disables the new card motion. Light, Dark, and System use the existing semantic website tokens; status emphasis uses success, warning, and danger tokens rather than hardcoded light-only fills.

The bento grids collapse below 1100px, Admission and tab layouts below 760px, and compact metric/request grids below 420px. Wide grade tables remain in contained horizontal scrollers. These breakpoints are source/build-verified, **not screenshot-verified**: no browser executable or screenshot tool was available. Manual checks at 1440, 1024, 768, 390, and 320px remain required, including Buddy overlap and Light/Dark/System contrast.

Website output grew only by the small CSS/template change (roughly tens of KiB in the generated PWA precache). Android and Electron output stayed on the legacy presentation path; no heavy UI or animation package was added.

## Validation

- Full frontend suite: **51 files passed, 0 failed**. The new Step 7 test compiles the three dashboard templates, checks source-driven actions, website-only selectors, reduced-motion coverage, and the unchanged 150ms opacity route transition.
- Website production build: passed with an inert HTTPS API placeholder for build-time validation.
- Android mobile-development build: passed.
- Electron renderer production build: passed.
- `git diff --check`: passed.
- No Laravel tests were run because no Laravel or database interface was changed.

## Manual UAT and limitations

1. At 1440, 1024, 768, 390, and 320px, open all three role dashboards. Confirm bento stacking, no overflow, readable metrics, authorized notices, and Buddy not covering actions.
2. In Light, Dark, and System, inspect Enrollment status and next action, Subjects, Schedule in card/timetable/list modes, and COR print preview.
3. As Guest, inspect no-application, draft, exam-eligible, in-progress, published-result, and admitted Admission states. Confirm the active exam has no new movement or timer disturbance.
4. Inspect published Grades, Monitoring risk summaries, AI Help separation, Document Request filters/details/timeline/claim-code guidance, and Student/Professor Settings.
5. As Professor, keyboard through grading tabs, class cards, score inputs, bulk assessment entry, save, and submission. Verify sticky headers and editable/read-only contrast at narrow widths.
6. Confirm Android and Electron appearance on actual devices/windows. Build success proves compatibility, not pixel-level isolation.

No browser screenshots or measured contrast audit were available. The redesign is ready for **manual visual review**, not a claim of final visual acceptance. Existing admission examination, bulk grading, and document-request business logic were not changed; any newly observed visual or accessibility defect should be addressed after UAT without broadening backend scope.
