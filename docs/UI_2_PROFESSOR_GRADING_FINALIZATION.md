# CDM Portal UI 2.0 — Professor Grading Finalization

## Status

**Feature Complete — Pending Final Manual UAT**

Professor Grading has reached its planned feature-freeze baseline. Automated frontend, relevant backend, and cross-platform compilation checks pass. This status is not production acceptance: real-browser visual/performance UAT and academic workflow acceptance remain required.

This final pass was completed locally on branch `dev` without a commit, push, deployment, migration, seeder, `.env` change, or development MariaDB access.

## Recovery and preservation

The Phase 5.2 pre-change checkpoint is:

`.local-recovery/professor-grading-phase5-2-prechange-20261028/`

- `tracked.patch`: SHA-256 `790be22ff8ebae9e7bcdfd27709205aa06bb4755e00498e7e29a32df9d664dcc`
- `untracked.tar.gz`: SHA-256 `3f3dce7fa09ef51e7c18cc58dee3744bd1f03089ccd799fb903bc52b1d0d7562`

All previous recovery checkpoints and all three Git stashes remain intact.

## Final feature inventory

The Website Professor grading experience now includes:

- assigned-class and grading-period selection;
- Registrar-inspired Grade Entry with grouped assessment headers and a separate criteria panel;
- Midterm, Finals, and calculated Final Grade views;
- single-cell editing and spreadsheet keyboard navigation;
- rectangular selection, Excel-compatible TSV copy/paste, validation, undo, and redo;
- one Save changes action, explicit discard, and partial-save reconciliation;
- assessment creation, automatic labels, bulk entry, column renaming, and archival;
- category-weight criteria editing and readiness calculations;
- local opt-in draft recovery with conflict review;
- Find Student with stable-ID search and automatic score-cell navigation;
- unified Edit in Excel download, preview, review, and transactional draft apply;
- separate Grade Submission with readiness, deadline, eligibility, confirmation, and Registrar handoff states.

No Event Management feature or new grading policy was introduced.

## Grade Entry layout status

The normalized table model produces one Student Information group, grouped assessment categories, and calculated-result columns with explicit widths. Website-specific canonical CSS disables horizontal and vertical sticky positioning for the Grade Entry sheet, preventing the earlier overlapping-header and broken-offset behavior. The table remains inside its own horizontal/vertical scroll region.

Automated structural regressions confirm:

- two-row header span alignment;
- no sticky-offset metadata in the Website table model;
- no sticky classes in the Website Grade Entry table;
- aligned identity, assessment, and calculated columns;
- contained scrolling and responsive toolbar/criteria layouts;
- readable Light/Dark/System semantic styling.

No additional layout change was made in Phase 5.2 because no new source-level or automated defect was confirmed. Left/middle/right scrolling, browser zoom, actual clipping, and header alignment on target browsers remain visual UAT items.

## Find Student final behavior

The obsolete Jump to score button and its disabled helper text are removed. Find Student remains permanently visible and uses the already-authorized workspace roster without a network request.

- Typing searches a reusable index and shows at most eight suggestions; typing alone never navigates.
- Pointer selection or keyboard Enter on an explicitly active option stores the stable `enrollment_subject_id` and navigates to the current active assessment.
- Changing the assessment while a Student remains selected navigates to that Student's corresponding cell.
- Navigation resolves stable Student and assessment IDs against the currently rendered roster/columns, applies the existing non-reactive cell selection, focuses the score input, and uses immediate nearest-edge scrolling.
- The selected Student stays visibly identified.
- A missing Student/assessment/cell never falls back to another row and is reported through both visible error state and the selection live region.
- Phase 5.2 adds a read-only guard: locked or otherwise non-editable periods retain the Student selection but do not try to focus a disabled score input.
- Navigation does not mutate a score, change clipboard/history/recovery state, call an API, save, or submit.

## Keyboard and clipboard controls

- Click selects one score cell; Shift+click extends a rectangle.
- Arrow keys move the active cell; Shift+arrow extends the range.
- F2 or typing enters score editing.
- Escape exits editing, then clears selection when used again.
- Enter moves through the same assessment column.
- Tab and Shift+Tab preserve native focus order.
- Ctrl/Cmd+C exports selected scores as plain TSV.
- Paste validates the entire rectangular payload before any local mutation. Invalid text, formulas, negatives, over-maximum values, and out-of-bounds ranges reject atomically.
- Clipboard blank cells preserve the current score; numeric zero remains an explicit zero.
- Ctrl/Cmd+Z undoes, while Ctrl+Y or Ctrl/Cmd+Shift+Z redoes browser-local score operations.
- History is bounded and scoped to class, grade-sheet version, and period.
- Shortcuts are scoped to score cells and do not capture normal search fields, dialogs, or other inputs.
- No per-keystroke network request exists. Save changes remains the normal server-write boundary.

## Assessment and criteria safety

Add Assessment, automatic label suggestions, custom labels, Bulk Entry, Edit Columns, rename, archive, maximum-score validation, category weights, and criteria saving continue to use existing server contracts and permissions.

Pending score drafts block assessment, bulk-entry, and criteria operations that would reload authoritative workspace data. Active column-label edits block class exit, Grade Submission, Vue route navigation, and browser unload. Midterm/Finals changes retain the established history/recovery boundaries. No calculation or policy formula was modified.

## Edit in Excel status

The existing five-step Website workflow remains unchanged:

1. Download Gradesheet.
2. Edit in Microsoft Excel.
3. Upload Edited File.
4. Review Changes.
5. Apply Changes.

The exported workbook keeps assessment score cells editable while protecting identity, metadata, and calculated fields. Preview validates signed metadata, ownership, roster, assessment definitions, versions, numeric bounds, formulas, hyperlinks, and conflicts without writing grades. Applicable proposals are opaque, account/sheet-bound, cache-backed, one-time, and expire after ten minutes.

Apply revalidates under locks inside one database transaction, is idempotent for immediate duplicate retries, writes audit evidence, and saves assessment scores as drafts only. It never submits grades to the Registrar. Existing unsaved browser score/column edits block workflow actions that could overwrite them. Successful apply reloads authoritative workspace/readiness data; failure never displays a false success. If the write succeeds but refresh fails, the UI reports that exact boundary.

## Draft recovery safety

Recovery remains opt-in, `sessionStorage`-based, and disabled by default. Records are scoped by authenticated account, class/subject/section, term, grade sheet/version, grading period, assessment/version, and score baseline/version. Writes are debounced and records expire under the existing policy.

Restore applies only eligible browser drafts. Newer or conflicting server values are shown for review and are not silently overwritten. Discard removes the scoped recovery copy without changing authoritative grades. Successful score saves clear the corresponding persisted drafts; partial failures leave unsuccessful score edits available. Account changes and logout cleanup prevent another Professor from receiving the prior account's recovery data in the same tab.

## Grade Submission workflow

Grade Submission remains a separate Website-only Professor route and is not part of Save changes or Excel Apply. It displays:

- authoritative readiness counts and blockers;
- missing Midterm/Finals requirements;
- finals submission window/deadline;
- final-grade weights;
- draft, submitted, returned, approved, and published states already supported by the backend;
- eligibility derived from existing permissions, readiness, status, and window rules;
- explicit Submit to Registrar confirmation.

No real submission was performed during final validation. Authorization, locked-window, readiness, return/review, and release behavior are covered by existing frontend and Laravel grading suites.

## Performance measurements

`node scripts/benchmark-grading-clipboard.mjs` ran 40 iterations per generated case. These are Node model-operation measurements only; they do not measure DOM rendering, paint, scrolling FPS, browser memory, or assistive-technology latency.

| Fixture/operation | Median | Maximum |
| --- | ---: | ---: |
| 30 × 10 cell projection | 0.070 ms | 1.269 ms |
| 30 × 10 range map | 0.044 ms | 2.175 ms |
| 100 × 20 cell projection | 0.178 ms | 1.003 ms |
| 100 × 20 range map | 0.173 ms | 1.468 ms |
| 300 × 40 cell projection | 0.842 ms | 2.174 ms |
| 300 × 40 range map | 1.147 ms | 4.466 ms |
| Single-cell local edit | 0.001 ms | 0.113 ms |
| Keyboard coordinate lookup | 0.014 ms | 0.150 ms |
| Undo + redo | 0.003 ms | 0.132 ms |
| 100-cell paste validation/apply | 0.128 ms | 1.503 ms |
| Student search + assessment navigation | 0.039 ms | 0.559 ms |
| 100-edit recovery create/validate | 1.033 ms | 4.167 ms |
| 20-column table model | 0.017 ms | 0.988 ms |

No model-layer bottleneck was observed. Browser automation/runtime was unavailable, so initial DOM rendering, horizontal scrolling, focus behavior, dialog performance, and large-roster interaction acceptance remain pending manual browser measurement.

## End-to-end regression evidence

Automated coverage collectively exercises assigned workspace access, period state, assessment validation, score draft/save behavior, calculation readiness, clipboard/history, recovery conflict handling, Find navigation, Excel export/preview/apply security and concurrency, submission eligibility, Registrar review/release, and authorization boundaries.

Negative coverage includes unauthorized access, invalid/locked scores, invalid assessment metadata, stale and conflicting workbook proposals, duplicate apply, unsaved browser changes, partial direct-save failures, refresh/network failure messaging, recovery expiry/conflicts, and non-Website isolation. No real Registrar submission or development-database write was performed.

## Validation results

Run locally on 2026-10-28:

- Focused grading frontend suite: **17/17 test files passed**, 0 failed.
- Full frontend suite: **70/70 test files passed**, 0 failed, in 9.501 seconds.
- Relevant Laravel grading suites using disposable in-memory SQLite: **50 tests passed, 675 assertions**, in 52.911 seconds.
- Website production build with inert build-time `VITE_API_BASE_URL=https://example.invalid/api`: **passed**, 356 modules, 12.62 seconds.
- Android mobile-development build: **passed**, 350 modules, 13.40 seconds.
- Electron desktop-development renderer build: **passed**, 350 modules, 11.80 seconds.
- `git diff --check`: passed at final verification.
- Laravel Pint was not run because Phase 5.2 changed no PHP file.
- Full Laravel was not required because no backend grading code or contract changed; the relevant grading suites cover the frozen grading boundary.

The frontend suite retains one existing non-failing CommonJS test-bundling warning about `import.meta` in `clientPlatform.js`; Vite's ESM builds are unaffected.

## Phase 5.2 files changed

- `CDM_Frontend/src/modules/grading/GradingView.vue` — read-only automatic-navigation guard and accessible explanation.
- `CDM_Frontend/test/gradingFindNavigation.test.js` — read-only navigation regression assertion.
- `docs/UI_2_GRADING_FIND_STUDENT_FIX.md` — final navigation boundary.
- `docs/UI_2_PROFESSOR_GRADING_FINALIZATION.md` — frozen feature inventory and validation baseline.

Phase 5.1 changes already present at the start of this pass remain in `GradeFindControl.vue`, `GradingView.vue`, `grading.css`, the Find navigation test, and the Find Student documentation. All unrelated uncommitted work was preserved.

## Remaining manual UAT

1. Use authorized disposable Professor data in a local environment; never use the hosted presentation database.
2. At 100%, 125%, 150%, and 200% zoom, inspect grouped headers, identity/assessment/result alignment, criteria readability, and toolbar clipping.
3. At 1440, 1024, 768, 390, and 320 px, scroll the gradesheet fully left, middle, and right. Confirm there are no sticky overlaps or page-level overflow.
4. Test single-cell entry, Tab/Shift+Tab, Enter, F2, Escape, arrows, Shift+arrows, click, and Shift+click.
5. Copy/paste values containing blanks and zero with desktop Excel; test formula, invalid text, negative, over-maximum, non-rectangular, and out-of-bounds rejection.
6. Verify undo/redo, Save changes, Discard, complete failure, and partial-save failure on disposable fixtures.
7. Select Students by pointer and keyboard, change assessments, and verify exact focus/scroll. In a locked period, confirm selection remains visible and the read-only explanation is announced without focusing a disabled input.
8. Test assessment suggestion, custom rename, bulk creation, archival, maximum validation, criteria totals, and period switching with pending-edit safeguards.
9. Complete the five-step Excel workflow in supported desktop Microsoft Excel. Test blank/zero, protected fields, invalid file, expiry, conflict, duplicate apply, and authoritative refresh.
10. Enable recovery, reload, restore/discard, test expiry/conflicting server updates, then log out and switch accounts in the same tab.
11. Review Grade Submission readiness, blockers, window, deadline, final weights, confirmation, and returned-state behavior without performing a real institutional submission.
12. Profile 30×10, 100×20, and 300×40 fixtures in the target browser for render time, scroll FPS, typing, search/navigation, paste, recovery, dialogs, and memory.
13. Repeat critical visual and keyboard checks in Light, Dark, and System themes with a screen reader.
14. Confirm Android retains its established mobile grading presentation and Electron Admin/Registrar workflows remain unchanged.

## Known issues and severity

### Blocking defects

None known from current source inspection and automated validation.

### Pending acceptance items — not established defects

- **High acceptance importance:** real-browser large-roster rendering/scroll performance and browser zoom layout have not been measured.
- **High acceptance importance:** academic staff must accept calculation/readiness/submission behavior using representative institutional fixtures.
- **Medium acceptance importance:** Windows desktop Excel edit/protection behavior needs final UAT in the supported Excel version.
- **Medium acceptance importance:** screen-reader announcements, native clipboard permissions, and theme contrast require target-browser/device verification.
- **Low:** the existing CommonJS `import.meta` warning appears only in one Node source-bundling test and does not fail tests or ESM builds.

## Feature-freeze declaration

Professor Grading is frozen at this baseline as **Feature Complete — Pending Final Manual UAT**. Further work should be limited to:

- defects reproduced during manual UAT;
- security, data-integrity, accessibility, or academic-policy corrections;
- regressions caused by an approved shared-platform change.

Do not add grading features or redesign the approved Grade Entry workspace during the Enrollment UI 2.0 phase. Any future grading change should begin with this document's automated suites and manual checklist as the regression baseline.

## Enrollment handoff considerations

- Preserve `enrollment_subject_id` stability and roster membership contracts; Grade Entry search, recovery, Excel reconciliation, and score writes use them as authoritative Student identity.
- Preserve assigned section/subject, academic-year, semester, and Professor ownership payloads used by the grading workspace.
- Do not change finalized Enrollment status semantics or silently reorder/remove authorized roster records without checking grading regression suites.
- Enrollment UI work must not alter grading formulas, grading-window rules, score versions, assessment versions, readiness, or submission authorization.
- Keep Website-only grading presentation gates intact while modifying shared Enrollment components or client-platform configuration.
- After Enrollment changes, rerun the focused grading frontend tests and relevant SQLite Laravel grading suites before considering the handoff safe.
