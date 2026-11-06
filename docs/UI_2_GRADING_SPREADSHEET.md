# CDM Portal UI 2.0 — Professor Grading Spreadsheet

## Scope

This step refines the Professor grade-entry workspace for the website client only. It keeps the existing grading API, score versions, assessment configuration, grading windows, sheet states, readiness checks, final weighting, Registrar submission, review, and release behavior unchanged. No Laravel, database, migration, environment, Android-presentation, or Electron-presentation file was changed.

The pre-change recovery checkpoint is `/tmp/cdm-grading-spreadsheet.6wfz2e`. It contains the original branch and HEAD, stash manifest, binary tracked-work patch, untracked-file archive, and SHA-256 manifest. The checkpoint records branch `dev` at `bf7a6893b525662e1edb56e9ac1e1f9d1e1ad828`; existing stashes were left intact.

## Issues found

- Score entry was visually fragmented into a general-purpose table with one Save button per assessment.
- A Professor could not see a single, reliable count of pending score edits across the sheet.
- Unchanged values were treated as changed as soon as they were typed, and empty or out-of-range drafts had no cell-level explanation.
- Assessment identity, period availability, deadline, and class context were spread across several large cards.
- Wide class sheets had horizontal scrolling, but the header and student identity needed a stronger spreadsheet treatment.
- Keyboard users could tab through inputs, but Enter did not support efficient same-column entry.
- Android and Electron share grading code, so a direct replacement would have changed their established presentation.

## Implementation

### Website spreadsheet workspace

The website Professor workspace now provides:

- a compact subject, section, term, active-period, status, deadline, and roster context area;
- Midterm, Finals, and Final Grade tabs using the existing active-period state;
- compact category-weight controls using the existing weight endpoint;
- Search student, Bulk Entry, and Add Assessment tools;
- a dense score grid with a sticky header, row-number column, and student identity column;
- horizontally scrollable assessment columns and a read-only raw-total column;
- normal, focused, changed, invalid, disabled, and saving feedback;
- native Tab and Shift+Tab traversal plus Enter-to-next-student in the same assessment column;
- one `Save changes` action for all pending score cells;
- an explicit pending-change count, discard action, and accessible invalid-score messages.

The existing Add/Edit/Archive assessment, bulk assessment, category weight, final weight, submission readiness, returned-sheet, Registrar submission, and final-grade views remain available.

### Score state and validation

`gradeSpreadsheet.js` provides small pure helpers for cell keys, numeric/max-score validation, numeric dirty comparison, and grouping pending cells by assessment. The UI considers numerically equivalent values such as `18` and `18.00` unchanged. Blank, nonnumeric, negative, and over-maximum drafts block Save and receive a cell-level message.

The existing API accepts one assessment per score request. The single website Save action therefore groups pending cells by assessment and sends those groups through the unchanged `gradingService.saveScores` endpoint. Version values are preserved for existing scores. This maintains backend authorization and validation without introducing a new contract.

This client-side grouping is not a new cross-assessment database transaction. A network or server failure after an earlier assessment group succeeds can produce a partial multi-request save; the UI retains its drafts and displays the existing API error. Adding transactional cross-assessment persistence would require a separately reviewed backend change and was intentionally outside this frontend-only step.

## Website-only isolation

The new workspace is selected by the existing compile-time `clientPlatform === 'web'` check. Its visual rules use spreadsheet-specific classes plus `.website-professor-grading` overrides, and the new spreadsheet markup is inside `v-if="isWebsitePresentation"`. Those spreadsheet-specific elements therefore do not exist in non-web clients. The previous Professor score-entry markup remains in the `v-else` branch for Android and Electron. Shared business functions and API paths are unchanged.

Light, Dark, and System appearance use existing semantic tokens. Cell transitions are limited to background/border feedback and are disabled by `prefers-reduced-motion`. No transform, route transition, navigation geometry, or CDM Buddy code was changed.

## Files changed for this step

- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/src/modules/grading/gradeSpreadsheet.js` (new)
- `CDM_Frontend/test/gradingSpreadsheet.test.js` (new)
- `docs/UI_2_GRADING_SPREADSHEET.md` (new)

The repository already contained unrelated uncommitted UI, CDM Buddy, academic, deployment-readiness, and backend work. It was preserved and is not claimed as part of this step.

## Automated validation

- Focused grading and website isolation tests: **passed**.
- Full frontend test suite: **passed — 57/57 test files**.
- Website production build: **passed** with command-only inert `VITE_API_BASE_URL=https://build.invalid/api`; no `.env` file changed.
- Android mobile-development build: **passed**.
- Electron desktop-development renderer build: **passed** with command-only inert `VITE_DESKTOP_API_BASE_URL=https://build.invalid/api`; no packaging was performed.
- `git diff --check`: **passed**.

The builds do not call Laravel, MariaDB, or a deployed portal API. No migration, seeder, or database command is part of this validation.

## Manual UAT

1. Sign in as a Website Professor and open Grading → My Classes.
2. Open a class at 1440px, 1024px, 768px, 390px, and 320px widths. Confirm the class/term context remains readable and the page itself does not overflow.
3. Switch between Midterm, Finals, and Final Grade. Confirm status/deadline text and the correct existing scores appear for each period.
4. Scroll a wide score sheet vertically and horizontally. Confirm the assessment header and student identity stay readable without covering score cells.
5. Search by student number and name. Confirm filtering does not discard existing drafts.
6. Enter several scores in different assessment columns. Confirm changed cells highlight and the pending count is accurate.
7. Re-enter a persisted score using different decimal formatting. Confirm it is not marked changed.
8. Enter blank, negative, and over-maximum values. Confirm cell messages appear and Save is disabled.
9. Use Tab, Shift+Tab, and Enter. Confirm normal tab order and same-column Enter navigation.
10. Save pending changes. Confirm one action saves all valid changed cells, shows progress/success, and reloads authoritative scores.
11. Trigger a safe validation/network failure in a non-production environment. Confirm the error is announced and the sheet remains usable.
12. Verify Add Assessment, Bulk Entry, Manage weights, final weights, submission readiness, returned-sheet correction, and Registrar submission still follow their existing permissions and windows.
13. Repeat visual checks in Light, Dark, and System themes, including focus rings, changed/invalid states, sticky surfaces, and disabled controls.
14. Enable reduced motion and confirm score-cell feedback has no transition.
15. Build or inspect Android and Electron grading screens and confirm their prior score-entry markup and presentation remain unchanged.

## Remaining manual limitations

- Automated compilation and source tests cannot prove sticky-column alignment, browser zoom behavior, or touch scrolling quality on every device.
- The widest real production class/assessment combination should be checked with representative authorized data.
- Cross-assessment Save uses the existing per-assessment endpoint and is not atomic across its grouped requests.
