# UI 2.0 Professor Grading Column Refinement

## Scope

This refinement applies only to the website Professor grading workspace. It keeps the existing grading calculations, readiness rules, permissions, score endpoints, bulk entry, and Registrar submission workflow unchanged. Android and Electron continue to render the existing non-web grading branch.

Recovery checkpoint: `/tmp/cdm-grading-columns.1P5UCB`

## Spreadsheet cell styling

- Score inputs now visually belong to the table grid: they have no permanent border, radius, shadow, or inset control background.
- The table's existing row and column boundaries remain the primary separators.
- A focused score cell receives a two-pixel inset emerald indicator. Because it is inset, focus does not move adjacent cells or change the table geometry.
- Numeric alignment, direct editing, Tab/Shift+Tab browser navigation, Enter-to-next-student behavior, maximum-score validation, dirty/invalid states, and the single `Save changes` workflow remain intact.
- Existing semantic surface, text, border, warning, danger, and accent tokens provide Light, Dark, and System theme behavior.

## Column-management interaction

Normal assessment headers show only the assessment label and `Category · /maximum` summary. The former repeated Edit and Remove buttons are absent from the website table.

`Edit Columns` switches the current grading period into an explicit management mode:

- Each active assessment label becomes an inline text field.
- A compact minus button appears once per assessment header.
- The toolbar provides separately scoped `Cancel` and `Save column changes` actions.
- Grading-period navigation is disabled while the mode is active to prevent drafts from being silently abandoned.
- Empty labels, labels over 80 characters, and case-insensitive duplicates are blocked with inline feedback.
- Score cells and the score save bar remain in place, but score entry pauses while column mode is active. Pending score work must be saved or discarded before entering column mode, so score and column drafts cannot be silently mixed or discarded by separate refreshes.

## Assessment label persistence

Renames use the existing assessment update endpoint and submit the current period, category, maximum score, display order, and optimistic-lock version together with the new label. Assessment IDs and existing scores are preserved.

When several labels change, the client sends one existing update request per changed assessment. This operation is not atomic. If an early request succeeds and a later request fails, the interface reports how many columns were saved, retains the remaining edit state, and does not claim that all changes rolled back.

## Assessment removal confirmation

The backend's supported removal behavior is archival, not physical deletion. The confirmation therefore explains that the assessment will be hidden from the active sheet while existing student scores remain stored.

- Removal is available only when the sheet/period is editable.
- Pending score changes must first be saved or discarded.
- Pending label changes must first be saved or canceled.
- Confirmation receives initial focus; cancel/close returns focus to the originating minus control.
- Escape and backdrop dismissal are supported through the shared modal close handler.
- Confirmation calls the existing assessment-status endpoint with the current optimistic-lock version and `archived` status.
- A successful archive reloads the authoritative workspace and readiness state. A failed archive leaves unrelated score data intact and displays the server error.

Archived assessments and historical scores remain in backend records. No database schema or backend behavior was changed.

## Save workflows and limitations

- `Save changes` continues to save score drafts in batches through the existing score endpoint.
- `Save column changes` changes labels only.
- `Archive assessment` changes assessment status only.
- The existing backend exposes no atomic multi-assessment rename operation, so multiple label changes are persisted sequentially and disclosed as such.
- The existing backend exposes archival rather than destructive assessment deletion; the UI intentionally does not claim otherwise.

## Files changed for this refinement

- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/src/modules/grading/gradeColumnManagement.js` (new)
- `CDM_Frontend/test/gradingColumnRefinement.test.js` (new)
- `docs/UI_2_GRADING_COLUMN_REFINEMENT.md` (new)

## Automated validation

- Focused grading tests: passed (column refinement, spreadsheet, and readiness suites).
- Full frontend suite: passed, 59 test files with no failures.
- Website production build: passed with the inert build-time URL `https://build.invalid/api`.
- Android mobile-development build: passed.
- Electron desktop-development renderer build: passed with the inert build-time URL `https://build.invalid/api`.
- `git diff --check`: passed.

No Laravel tests were required because no backend file or contract changed. No database commands, migrations, seeders, or environment-file changes were run.

## Manual UAT checklist

Test with a Professor account and an editable grade period:

1. At 1440px and 1024px, open a class and confirm score cells resemble spreadsheet cells without rounded inner boxes.
2. Click and keyboard-tab through scores; verify the emerald indicator encloses the active cell without layout movement.
3. Press Enter in a score cell and verify focus moves to the same assessment for the next visible student.
4. Enter valid, empty, negative, non-numeric, and above-maximum values; verify validation, dirty count, discard, and one `Save changes` action.
5. Select `Edit Columns`; verify only active headers expose label fields and compact minus controls.
6. Try an empty, over-80-character, and duplicate label; verify `Save column changes` remains unavailable with inline feedback.
7. Rename one and then several assessments; verify labels persist after reload and existing scores remain unchanged.
8. Cancel a label edit; verify the saved label is restored and score drafts remain unchanged.
9. With an unsaved score, click a minus control; verify archival is blocked until score work is saved or discarded.
10. Confirm removal text names the assessment and states that existing scores remain stored. Cancel with the button, Escape, and backdrop; verify focus returns to the minus control.
11. Confirm an archive; verify the column disappears, other scores remain, readiness refreshes, and no assessment record is physically deleted.
12. Repeat key checks with a locked/submitted period; verify column editing, removal, and score editing are unavailable.
13. Review at 768px, 390px, and 320px; verify the table scrolls inside its region, the page itself does not overflow, sticky identity remains usable, and toolbar controls remain reachable.
14. Repeat in Light, Dark, and System themes; verify readable gridlines, headers, errors, dirty cells, and emerald focus.
15. Open Android and Electron builds; verify their pre-existing grading presentation is unchanged.

Browser screenshot automation was not available in this environment, so the viewport and visual checks above remain manual UAT requirements.
