# CDM Portal UI 2.0 — Grade Entry Layout Repair

## Scope and recovery

This repair is limited to the Website Professor Grade Entry spreadsheet. It preserves the Registrar-inspired page, separate Grade Submission route, grading APIs, formulas, validation, authorization, and all non-web grading presentation.

Latest pre-rendering-fix recovery checkpoint: `/tmp/cdm-grade-rendering-fix.CPztJq`

- Branch: `dev`
- HEAD: `bf7a6893b525662e1edb56e9ac1e1f9d1e1ad828`
- Tracked working-tree patch SHA-256: `c2a95fdf1eacd1487be15d70e78c29de6ea74122bc4b63755366f1aef3b67c87`
- Untracked archive SHA-256: `aab049ed39f55682d373a9e1dd6b6d6ddbd89b021d7a7b7ed88cc48c16ce8470`

All three existing stashes remain untouched.

## Confirmed root causes

The source audit found that the table's logical column counts were valid before the repair, but its rendered geometry was not derived from the same structure:

1. Header category arrays and body assessment arrays were maintained as separate computed structures. They happened to use the same source records, but there was no single model enforcing identical order and count.
2. The table had no explicit `<colgroup>`. With `table-layout: fixed`, leaf widths were inferred while colspan headers also declared competing minimum widths.
3. Sequence, student-number, and student-name widths and `left` offsets were repeated as unrelated hardcoded CSS values across the original spreadsheet, Grading 3.0, Grade Entry 4.0, responsive rules, and the refinement pass.
4. Successive CSS generations defined different identity widths: 46/116/190, 52/132/220, and mobile overrides. Sticky offsets could therefore disagree with the actual rendered column width.
5. Category headings declared minimum widths independently from their leaf assessment columns. A colspan heading could claim geometry different from the columns beneath it.
6. The sticky Student Information colspan header relied on its own width rather than the combined width of the same student-number and student-name columns.
7. Long labels were clipped inconsistently because their width was not tied to a deterministic column definition.
8. Global website academic table rules do not directly target `.grade-sheet-scroll`; the primary failure was the grading module's accumulated geometry, not transforms or absolute positioning. No table-cell transforms or absolute header positioning were found.
9. The first structural repair normalized the model but retained an old CSS sticky layer. That layer still positioned the grouped Student Information header and identity cells independently, leaving a browser-dependent overlap path. The first header row also grouped only Student Number and Student Name while Sequence occupied a separate row-spanning cell, adding avoidable complexity.

## Before and after structure

Before:

- One computed grouping for first-level headings.
- A separately flattened array for second-level headings and score cells.
- No `<colgroup>`.
- Widths and sticky offsets encoded repeatedly in CSS.
- Hardcoded result `colspan="5"` and identity `colspan="2"`.

After:

- `buildGradeTableModel()` normalizes every rendered column.
- One model provides identity columns, assessment groups, ordered assessment leaves, Raw Total, and supported calculated results.
- `<colgroup>` renders directly from `gradeTableModel.columns`.
- First-level group spans come from `group.columns.length`.
- Second-level headings and body score cells iterate the same `assessmentColumns` array.
- Result headings and body results iterate the same `resultColumns` array.
- Student Information and Calculated Results derive their colspans from model lengths.
- The table exposes its calculated column count and total width for structural verification.
- Student Information now spans all three identity columns: Sequence, Student Number, and Student Name.
- Both header rows and all body cells use normal table flow. Horizontal and vertical sticky positioning is deliberately disabled in the Website gradesheet.

## Authoritative column model

`gradeTableModel.js` defines the current deterministic widths:

| Column | Width |
|---|---:|
| Sequence | 48px |
| Student number | 124px |
| Student name | 200px |
| Assessment | 116px each |
| Raw total | 104px |
| Standard calculated result | 116px each |
| Remarks | 128px |

The model excludes archived assessments, omits categories with no rendered assessments, groups active assessments by their real category, and preserves one consistent grouped order for headings, inputs, and keyboard column indexes.

Long labels do not alter column geometry. Category and assessment labels wrap inside their assigned header cells; assessment labels also retain the complete value in the native title text.

## Rendering strategy

Correct table flow takes precedence over frozen columns. The Website gradesheet therefore uses:

- one explicit `<colgroup>` for all nine fixed columns plus active assessments;
- `table-layout: fixed` and an exact model-derived table width;
- a single horizontal scroll container around the whole table;
- normal, non-sticky positioning for both header rows and all identity cells;
- a 372px Student Information group derived from its three identity columns;
- wrapped essential header labels instead of ellipsis or overlay positioning.

No `stickyLeft`, `--grade-sticky-left`, or Website gradesheet `left` offset remains. The non-website fallback presentation is unchanged for Android/Electron isolation.

Structurally, removing sticky positioning eliminates the independently positioned layers that could cover category headings or score cells. This is the confirmed source-level correction. Because no supported browser renderer is available in this environment, it has not yet been proven visually against the supplied failing scroll positions; sticky behavior was not restored.

## Interaction preservation

The repair keeps:

- Direct borderless score entry.
- One cell-level focus indicator.
- Native-spinner suppression.
- Tab and Shift+Tab browser navigation.
- Enter-to-next-student behavior using the normalized assessment index.
- Dirty and invalid states.
- One Save changes operation.
- Add Assessment and automatic naming.
- Edit Columns and assessment archival safeguards.
- Bulk Entry and criteria editing.
- Midterm, Finals, and Final Grade navigation.
- Separate Grade Submission and unsaved-navigation protection.

No grading service, endpoint, formula, authorization rule, or database structure changed.

## Files changed

Added:

- `CDM_Frontend/src/modules/grading/gradeTableModel.js`
- `CDM_Frontend/test/gradeTableModel.test.js`
- `CDM_Frontend/test/gradeEntryRenderingFix.test.js`
- `docs/UI_2_GRADE_ENTRY_LAYOUT_REPAIR.md`

Modified:

- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/test/gradeEntryRefinement.test.js`
- `CDM_Frontend/test/gradingColumnRefinement.test.js`
- `CDM_Frontend/test/gradingFocusReadiness.test.js`
- `CDM_Frontend/test/gradingThree.test.js`
- `CDM_Frontend/test/registrarGradesheetAlignment.test.js`

## Automated validation

- Focused rendering/model regression files: 4 passed, 0 failed.
- Full frontend suite: 65 test files passed, 0 failed.
- Website production build: passed using command-scoped `VITE_API_BASE_URL=https://example.invalid/api` as an inert HTTPS build placeholder. No `.env` file changed.
- Android development build: passed.
- Electron renderer build: passed.
- `git diff --check`: passed with no whitespace errors.

The model and rendering tests explicitly cover no assessments, 1, 5, 10, and 20 assessments, multiple assessments in one category, Quiz/Activity/Recitation/Major Exam grouping, addition, archival, long labels, result columns, removal of sticky metadata, period filtering, and website-only isolation.

## Browser-verification result

Rendered browser verification could not be performed in this session. The workspace exposes no browser-control tool, Chromium/Chrome executable, Playwright, Puppeteer, or `cua_repl.js`. The attachment directory also contains only the written request, not the two screenshot image files.

Consequently:

- No screenshot was captured.
- No live `getBoundingClientRect()` measurements were available.
- No claim of pixel-perfect or visually accepted output is made.
- Structural consistency is verified through the shared model, explicit colgroup, source assertions, Vue production compilation, and platform builds.

Manual rendered inspection remains required before acceptance.

## Remaining limitations

- The gradesheet intentionally scrolls horizontally when its deterministic column width exceeds the available viewport.
- Frozen headers and identity columns are intentionally unavailable in this repair; the whole table scrolls together to prevent overlapping layers.
- Browser rendering must still be checked in the browsers used for UAT. Automated source tests and compilation cannot prove the absence of a visual overlap.
- Real API data is required to exercise every assessment mix and a large roster; no database command or record change was authorized.

## Manual UAT checklist

1. Sign in as a Website Professor and open a real assigned class.
2. Verify Case A with no active assessment columns: identity, Raw Total, and five calculated columns must align.
3. Add one assessment and verify its category spans exactly one column.
4. Add multiple Quiz assessments and verify Quiz expands without moving other headings into Student Information.
5. Add Activity, Recitation, and Major Exam assessments and verify every category sits directly above its own leaves.
6. Add another Quiz and verify the Quiz span increases by one while body score order matches the header.
7. Archive an assessment and verify its header, score cells, and group span all disappear together.
8. Rename an assessment and category to long labels and verify each wraps inside its own header without entering an adjacent column; hover the assessment for the full title.
9. At horizontal scroll positions 0, middle, and maximum, compare the left/right edges of every header with its body cells.
10. Confirm Sequence, Student Number, Student Name, assessment columns, Raw Total, and calculated results scroll together without frozen or overlaid cells.
11. Scroll a large roster vertically and verify both header rows remain in normal flow and retain column alignment after returning to the top.
12. Switch repeatedly between Midterm and Finals and confirm each period constructs a fresh, valid column set.
13. Enter scores with mouse, Tab, Shift+Tab, and Enter; verify one focus indicator and unchanged validation.
14. Exercise Save changes, discard, Add Assessment, Edit Columns, removal safeguards, Bulk Entry, and Criteria.
15. Repeat at 100%, 125%, and 150% browser zoom on desktop.
16. Repeat at 1440px, 1180px, 1024px, 768px, 390px, and 320px.
17. Repeat in Light, Dark, and System themes.
18. Open Android and Electron grading and confirm their existing presentation is unchanged.
