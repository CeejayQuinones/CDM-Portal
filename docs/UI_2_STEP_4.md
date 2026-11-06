# UI 2.0 Step 4 — Website QA

## Scope and recovery

Local dev, HEAD bf7a6893b525662e1edb56e9ac1e1f9d1e1ad828. Existing work and three stashes preserved. Durable source checkpoint: `.local-recovery/ui2-step4/` (local recovery artifacts, not intended for commit). Tracked patch SHA256: 48c3146817fc29662ecc2ab09a96df2561e9c9506390de8e159c7d7fad0e2cf0. Untracked archive SHA256: a0f36615313d154da901d342ce925378a5ee7ad184dabbf626519c672e7ccf8b. Archive listing verified readable.

## Issues recorded and repaired

| Severity | Evidence/root cause | Repair |
| --- | --- | --- |
| Moderate | Topbar search had no Escape handler; global Escape could close the secondary panel while search stayed open. | Close search, stop propagation, restore trigger focus; connect trigger to popover with aria-controls. |
| Moderate | Narrow topbar title lacked explicit flexible sizing; controls could shrink with long titles. An nth-of-type rule targeted a nonexistent second link. | Flexible, truncated title and nonshrinking controls; reduce narrow-width gaps without hiding actions. |
| Major | COR/schedule print parent colors did not override dark descendant table cells; website rail offset remained in print layout. | Website-only print overrides hide shell controls, remove rail offset, use white paper/black text, and remove table minimum widths. |

These are source-confirmed issues. No browser-rendered defect claims or screenshots are made. No Chromium/Chrome executable was found; no browser tooling was installed.

## Files changed

- src/components/website/WebsiteTopbar.vue: keyboard and narrow-width fixes.
- src/assets/styles/website-academic-modules.css: official-document print isolation.
- test/websiteUiTwoStepFour.test.js: executable Escape handler regression and compiled print-rule scope checks.
- docs/UI_2_STEP_4.md: this report.

All source/test paths above are relative to CDM_Frontend. No backend changes were required.

## Coverage and boundaries

Existing regressions cover authorized role navigation, Professor Settings, account-scoped themes, Admission and exam safeguards, Enrollment/Subjects/Schedule, Grades and bulk assessment, Monitoring/AI Help, Document Requests, Buddy greetings/mute/docking, Settings exclusions, Event removal, and stable transitions. The 150ms opacity transition and route keys remain unchanged. New presentation edits are limited to website components and root-scoped website print CSS; Android/Electron presentation is preserved.

Performance observation: large duplicated document-request CSS chunks remain in builds. Consolidating that shared stylesheet would affect multiple platforms and needs separate measurement and review; it was not changed during this pass. No dependencies, watchers, API calls, or page-entry animations were added.

## Validation

Full frontend suite including Step 4: 47/47 files passed. Website production build passed with inert https://api.example.invalid/api. Android mobile-development build passed. Electron renderer passed; packaging required an approved network retry after sandbox DNS failure. git diff --check passed. No database operations or Laravel tests were needed for these presentation-only changes.

## Manual screenshot and UAT matrix

Final Electron retry completed successfully (exit 0), including Linux AppImage packaging. Only existing desktopName/category metadata recommendations remain.

Visual acceptance remains pending. At 1440, 1024, 768, 390, and 320 pixels, capture representative Student, Guest, and Professor dashboards in Light and Dark. Repeat with System while changing OS appearance.

1. Open rail destinations, secondary panels, search, account popover and mobile drawer using keyboard and touch. Check long names, current-route indicators, Escape, focus restoration and no horizontal page overflow.
2. Student: Enrollment, Subjects, Schedule, COR, Grades, Monitoring/AI Help, Document Requests and Settings. Check wide tables scroll within their container and action buttons remain visible.
3. Guest: Admission status, upload requirements/previews and entrance examination. Verify partially completed forms and exam safeguards survive normal interactions.
4. Professor: assignments, rosters, grading, bulk entry, submission and Settings. Check editable/read-only fields, errors and draft/submission status.
5. Open dialogs in each theme; inspect native selects, autofill, placeholders, status contrast, focus trap and restoration. Confirm critical forms are not dismissed unexpectedly.
6. Test Buddy near both rail and panel, on mobile, and with speech open; drag/dock, mute/manual open and required acknowledgment. Confirm Settings exclusion.
7. Print COR and schedule from Dark mode; verify margins, page breaks, white cells, legible text and absence of portal chrome. Check other supported academic exports.
8. Enable reduced motion and rapidly navigate academic modules; confirm no wiggle, duplicate animation or scroll jump.
9. Smoke-test installed Android and Electron presentation and role boundaries.

No screenshot-based assertion of full visual consistency is possible from this environment. Remaining visual defects, if any, must be identified through this matrix before acceptance.
