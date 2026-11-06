# CDM Portal UI 2.0 — Professor Grading Phase 4A

## Scope and safety

Phase 4A adds website-only spreadsheet interactions to Professor Grade Entry. It does not change Laravel, grading formulas, authorization, database structure, Grade Submission, or the Phase 3 **Edit in Excel** services and workflow. Clipboard edits continue to use the existing `scoreDrafts` state and remain browser-local until the Professor chooses **Save changes**.

Recovery checkpoint created before implementation:

- Path: `/tmp/cdm-grading-4a-prechange.tDcT0z`
- Tracked patch SHA-256: `59af3ff4380485bbaab350736c1b9fa69e90f28a088fbc97094ce6cd17215358`
- Untracked archive SHA-256: `7c1d84f7c0f63fb731ab1b635f8f5cb1dfc34bbedb2034f5b4437d15d2af5a92`
- The existing three Git stashes were not modified.

## Selection interaction model

- Click selects one editable assessment score cell.
- Shift+click extends a rectangle from the current anchor.
- Arrow keys move the active cell while in navigation mode.
- Shift+arrow extends the selected rectangle.
- Typing or double-clicking enters editing mode. `F2` also enters editing mode.
- `Escape` first leaves editing mode; a second `Escape` clears the selection.
- `Enter` moves to the next Student row in the same assessment column.
- Tab and Shift+Tab retain normal browser focus order through score inputs.
- Selection is represented by stable `enrollment_subject_id` and assessment ID pairs. Visible row indexes are resolved again after filtering, so a clipboard operation cannot silently target a different Student.
- Identity and calculated-result cells have no score-cell coordinates and cannot become paste targets.

Selection painting is intentionally non-reactive. It updates only the affected table-cell classes, avoiding a Vue table rebuild for every arrow-key or range-selection movement. The active cell has one emerald inset border; the rest of the range uses a muted semantic-token background. No sticky columns or new selection animations were introduced.

## Clipboard rules

Copy uses plain-text TSV in visible row/column order, compatible with desktop Excel:

- `0` remains an explicit zero.
- Empty scores become empty TSV fields.
- Only assessment score values are copied; Student identifiers and calculated fields are excluded.
- Non-numeric text is prefixed and normalized before clipboard output to prevent formula execution if an unexpected value reaches the copy path.
- The Clipboard event API is preferred. `navigator.clipboard.writeText` and a temporary read-only text-area copy are fallbacks.
- Copy/paste handlers are scoped to score cells. Search fields, dialogs, and assessment-label inputs keep native text editing and undo behavior.

Paste accepts rectangular tab/newline-delimited plain text beginning at the active score cell. Before changing local state, the complete range is checked for:

- a valid active score target;
- an editable and unlocked grading period;
- rectangular shape;
- roster/assessment boundaries;
- finite numeric values;
- formula or formula-like values;
- scores below zero or above the assessment maximum.

Any validation error rejects the entire operation and displays row/assessment locations. Paste never sends a network request.

## Blank and zero handling

The safe default is:

- blank clipboard cell: leave the existing score unchanged;
- `0`: set an explicit zero score;
- nonblank valid number: create/update the local draft.

The success message reports skipped blank cells. Clearing an existing score remains an intentional direct-cell action subject to the existing score validation; paste does not use blanks as an implicit delete command.

## Undo and redo

- Ctrl/Cmd+Z undoes the latest unsaved operation.
- Ctrl+Y and Ctrl/Cmd+Shift+Z redo it.
- A focused typing session in one cell is coalesced into one operation.
- A valid multi-cell paste is one operation.
- New edits clear the redo branch.
- History is bounded to 50 operations.
- Each operation is scoped to class, grade-sheet version, and grading period.
- Opening another class, returning to the class list, or switching periods clears history and selection.
- Discard clears drafts and history.
- A completely failed save preserves drafts and history. If a multi-assessment save partially succeeds, persisted batches are removed from drafts and history is cleared at that server-write boundary; remaining unsaved drafts stay visible.
- A successful save/reload establishes a new history boundary.

Shortcuts are handled only when their event originates in an assessment score cell. Native undo remains available in search, dialog, and other text inputs.

## Data safety boundaries

- `scoreDrafts` remains the single source of unsaved browser scores.
- Copy/paste/undo/redo do not call `gradingService`.
- The existing **Save changes** action is the only normal persistence path for browser-edited scores.
- Existing per-assessment maximum validation and period locks remain authoritative.
- No identity, roster metadata, calculated result, formula, grade-submission, or Phase 3 Excel endpoint was changed.
- No backend file, database configuration, migration, seeder, or MariaDB record was touched for Phase 4A.

## Performance strategy

- Stable normalized assessment columns are reused from `gradeTableModel`.
- Selection state and undo stacks are non-reactive local structures.
- Selection class painting is limited to the grade table and runs after relevant Vue updates.
- Paste parses and validates in one local pass, then applies one shallow draft update.
- No deep watchers, per-keystroke network requests, or spreadsheet dependency were added.
- The repeatable fixture benchmark is `node scripts/benchmark-grading-clipboard.mjs`.

### Benchmark results

Measured locally on generated data, 40 runs per case. These numbers cover interaction/model preparation, not browser DOM paint or GPU rendering.

| Fixture | Median | Maximum |
| --- | ---: | ---: |
| 30 rows × 20 assessments, cell projection | 0.113 ms | 3.951 ms |
| 30 rows × 20 assessments, full-range map | 0.149 ms | 1.494 ms |
| 100 rows × 20 assessments, cell projection | 0.375 ms | 4.453 ms |
| 100 rows × 20 assessments, full-range map | 0.267 ms | 3.044 ms |
| 300 rows × 20 assessments, cell projection | 0.989 ms | 6.470 ms |
| 300 rows × 20 assessments, full-range map | 0.621 ms | 11.346 ms |
| Single-cell local edit | 0.001 ms | 0.133 ms |
| Keyboard navigation coordinate lookup | 0.014 ms | 0.231 ms |
| Single operation undo plus redo | 0.005 ms | 0.200 ms |
| 100-cell paste validation plus apply | 0.217 ms | 7.677 ms |
| 20-column normalized model build | 0.032 ms | 0.237 ms |

Actual 300-row browser scrolling and paint smoothness still require manual UAT on the target browser/hardware.

## Files changed for Phase 4A

- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/src/modules/grading/gradeSpreadsheetInteraction.js` (new)
- `CDM_Frontend/scripts/benchmark-grading-clipboard.mjs` (new)
- `CDM_Frontend/test/gradingClipboardEditing.test.js` (new)
- `CDM_Frontend/test/gradingSpreadsheet.test.js`
- `CDM_Frontend/test/gradingColumnRefinement.test.js`
- `CDM_Frontend/test/gradingFocusReadiness.test.js`
- `docs/UI_2_PROFESSOR_GRADING_4A.md` (new)

## Automated validation

- Focused Phase 4A, spreadsheet, and Phase 3 Excel tests: passed.
- Full frontend suite: 67 test files passed, 0 failed.
- Website production build with inert build-time API URL `https://example.invalid/api`: passed.
- Android mobile-development build: passed.
- Electron desktop-development renderer build: passed.
- `git diff --check`: see the final task report.
- No Laravel tests were required because Phase 4A changed no backend contract.

## Manual browser UAT checklist

1. Open Website Professor Grade Entry for an editable class with several Students and assessments.
2. In Light, Dark, and System themes, click one score cell and confirm one active border without an overlapping focus outline.
3. Shift+click another score cell; confirm the rectangular range and that horizontal scrolling does not detach the selection.
4. Use arrows and Shift+arrows. Confirm navigation/range behavior, then test Tab, Shift+Tab, Enter, F2, and Escape.
5. Copy a range containing a value, zero, and blank. Paste into desktop Excel and confirm TSV alignment and blank/zero semantics.
6. Copy a rectangular Excel range and paste at an active score cell. Confirm one local update, unsaved status, and no network save.
7. Try a formula, text value, negative score, over-maximum score, non-rectangular range, and a range crossing the last assessment/Student. Confirm the whole paste is rejected.
8. Paste a range containing blanks and confirm existing values under blanks do not change.
9. Undo and redo a typed cell and a multi-cell paste. After undo, make a new edit and confirm the old redo branch is unavailable.
10. Filter the roster, paste into visible rows, clear the filter, and confirm only the intended stable Student records changed.
11. Switch Midterm/Finals and classes. Confirm old history cannot affect the new scope.
12. Simulate a fully failed save and confirm drafts/history remain. For any partially saved request, confirm remaining drafts stay visible and the UI reports the history reset boundary.
13. Confirm locked periods reject paste and all score inputs remain disabled.
14. Confirm **Edit in Excel** still completes download, upload, preview, review, and apply exactly as Phase 3 defined.
15. Confirm Grade Submission remains separate and calculated/identity cells remain protected.
16. Repeat with approximately 30, 100, and 300 generated/demo rows; inspect typing latency, keyboard navigation, paste latency, and horizontal/vertical scrolling.
17. Verify Android and Electron Grade Entry presentation has no new selection UI or layout changes.

No automated browser screenshot or real-device performance capture was available in this session; visual alignment, theme contrast, native clipboard permissions, and large-roster scroll feel remain manual UAT items.
