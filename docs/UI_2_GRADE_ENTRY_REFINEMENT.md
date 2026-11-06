# CDM Portal UI 2.0 — Grade Entry Refinement

## Scope and safety

This pass refines the existing Website Professor Grade Entry workspace. It does not change Laravel, MariaDB, grading formulas, APIs, authorization, environment files, Android presentation, Electron presentation, Grade Submission separation, or any academic workflow rule.

Recovery checkpoint: `/tmp/cdm-grade-refinement.Ql19zx`

- Branch at capture: `dev`
- HEAD at capture: `bf7a6893b525662e1edb56e9ac1e1f9d1e1ad828`
- Tracked patch SHA-256: `93f9fba65e60d3c7d41db01e5ec33df37c4870f30167fcc338adc839ba7aa033`
- Untracked archive SHA-256: `0589862724e42118f658870b173e93b3a9d336e000877d25c69ce6c614620ba6`

The three existing Git stashes were preserved and were not applied, dropped, or modified.

## Screenshot and source issues identified

The supplied observations and the current implementation showed the following concrete issues:

1. First-level category headers shared only the narrow widths inherited from their child cells, leaving labels such as Recitation and Major Exam visually crowded.
2. Both header rows were only 38px high, with limited padding and weak separation between category groups.
3. Score columns were 86–90px while student identity columns were comparatively tight, producing a compressed table rather than a practical working sheet.
4. Category transitions continued through the header without a strong corresponding boundary through score rows.
5. The toolbar was a generic wrapping flex row, so search and action alignment became uneven as available width changed.
6. The save area used plain text on the same surface as the sheet and did not clearly distinguish saved, pending, and invalid states.
7. The criteria column could reduce the main spreadsheet too aggressively at intermediate widths.
8. The scroll region worked, but lacked a stable scrollbar gutter and visually contained spreadsheet treatment.

## Layout fixes applied

- Introduced explicit spreadsheet width variables for sequence, student number, student name, and score columns.
- Increased the desktop identity widths to 52px, 132px, and 220px respectively.
- Increased score columns to 104px and the minimum sheet width to 1280px.
- Increased ordinary row height to 46px.
- Increased the first and second header rows to 46px and 54px and synchronized the sticky offsets.
- Made each category header reserve space based on its real assessment count through `--assessment-count`.
- Added explicit start markers to assessment groups in both the header and body so category boundaries remain aligned while scrolling.
- Strengthened the Raw Total and Calculated Results boundaries.
- Kept sequence, student number, and student name independently sticky.

## Spreadsheet and cell refinements

- The two header levels now use distinct semantic emerald surfaces, consistent line heights, stronger gridlines, and more deliberate padding.
- Group labels are isolated inside their own span, protected from neighboring text, and use controlled overflow behavior.
- Editable score inputs remain borderless and integrated into their cells.
- Score values are centered with tabular numerals and a single cell-level focus outline.
- Native numeric spinners remain suppressed.
- Dirty and invalid states remain semantic and visually distinct without adding an inner input border.
- Horizontal scrolling now uses a stable gutter, contained overscroll, and themed thin scrollbars where supported.
- Raw totals and calculated values remain read-only and visually separate from editable cells.

No scoring, validation, save batching, keyboard movement, or readiness calculation was changed.

## Toolbar and criteria panel

The toolbar is now a two-column academic workspace row on wide screens:

- Left: labeled Student search with a stable input width.
- Right: primary `+ Add Assessment`, followed by secondary Edit Columns, Bulk Entry, and Criteria actions.

At 820px and below, the toolbar becomes a single-column layout. Existing small-screen action grids remain active at narrower widths.

The criteria panel now:

- Uses a 270–310px reference-column range on wide screens.
- Has stronger borders, denser but readable table spacing, and explicit column proportions.
- Retains category, maximum score, editable weight, total validation, and Save criteria.
- Stacks below the sheet at 1180px and below so it cannot crush the spreadsheet.

## Save-state strip

The strip remains connected to the sheet and now exposes one of three presentation states:

- Saved: `All score changes are saved.` with the success treatment.
- Dirty: `1 unsaved score.` or `X unsaved changes.` with the warning treatment.
- Invalid: the existing score-attention message with the danger treatment.

The helper message remains below the primary state. Discard changes and Save changes stay grouped on the right and retain their original disabled conditions and handlers.

## Files changed

Modified:

- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/test/registrarGradesheetAlignment.test.js`

Added:

- `CDM_Frontend/test/gradeEntryRefinement.test.js`
- `docs/UI_2_GRADE_ENTRY_REFINEMENT.md`

## Validation results

- Focused grading refinement tests: passed, 22 tests across four test files.
- Full frontend suite: passed, 63 test files and 0 failures.
- Website production build: passed using `VITE_API_BASE_URL=https://example.invalid/api` only as an inert command-scoped HTTPS build placeholder. No environment file was modified.
- Android development build: passed with `npm run mobile:development:build`.
- Electron renderer build: passed with `npx vite build --mode desktop-development`.
- `git diff --check`: passed with no whitespace errors.

The Android and Electron builds compile the existing non-web grading branch. The new structure and refinement styles remain gated by `isWebsitePresentation` and `.website-professor-grading`.

## Remaining limitations

- Automated browser screenshot capture is unavailable in this session, so final rendered comparison is intentionally left to manual UAT.
- Very wide grade sheets still require horizontal scrolling by design; columns are no longer compressed merely to avoid it.
- Native scrollbar styling varies by browser, although scrolling and stable gutter behavior do not depend on the custom appearance.
- The underlying grading payload still does not provide preliminary, contribution, or separately rounded-grade columns; this refinement does not fabricate them.

## Manual visual UAT checklist

1. Sign in as a Professor on the Website and open an assigned class with several assessment categories.
2. At 1440px, confirm Student Information, every category, Raw Total, and Calculated Results are clearly separated and no labels touch or overlap.
3. Confirm student number and name have sufficient room for realistic records.
4. Scroll horizontally and vertically; verify both header rows and all three identity columns remain aligned and stable.
5. Focus score cells using mouse, Tab, Shift+Tab, and Enter. Confirm one clean active-cell outline and no native number spinners.
6. Enter valid and invalid scores and verify clean dirty/invalid styling and unchanged validation messages.
7. Confirm the save strip changes among saved, one unsaved score, multiple unsaved changes, and invalid states.
8. Verify Discard changes and Save changes enable and disable correctly and that one save action persists all pending score batches.
9. Exercise Add Assessment, automatic labels, Edit Columns, Bulk Entry, and Criteria.
10. Edit criteria weights, confirm total validity styling, save, and reload the class.
11. Repeat at 1180px and 1024px; confirm criteria stacks below the sheet without narrowing score columns.
12. Repeat at 820px, 768px, 390px, and 320px; confirm toolbar actions wrap cleanly and page-level overflow does not appear.
13. Repeat in Light, Dark, and System themes and inspect header hierarchy, focus contrast, status strips, gridlines, and calculated cells.
14. Open Android and Electron grading views and confirm their legacy presentation remains unchanged.
15. Confirm Grade Submission remains separate and unsaved-change protection still prevents accidental navigation.
