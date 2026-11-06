# CDM Portal UI 2.0 — Registrar Gradesheet Alignment

## Scope and recovery

Professor Grade Entry 4.0 is a website-only presentation refinement built on the existing Grade Entry 3.0 and separate Grade Submission workflow. It does not change Laravel, grading formulas, APIs, authorization, database schema, migrations, environment files, Android presentation, or Electron presentation.

Recovery checkpoint: `/tmp/cdm-grading-4.0.UW7j9U`. It records the `dev` branch and original HEAD, stash inventory, tracked working-tree patch, untracked-file archive, and SHA-256 checksums.

## Reference availability and structure analysis

The written brief describes two reference images, but the attachment directory available to this session contains only `Pasted text.txt`. The current Grade Entry screenshot and primary Registrar gradesheet photograph were not available as image files. Consequently, this implementation follows the explicit structural description below, but a rendered side-by-side visual comparison and any claim of high-fidelity matching remain pending.

The described Registrar sheet is organized as one dense academic document:

1. Institutional seal and institutional identity.
2. Gradesheet identification, semester, academic year, and notes.
3. Course/program, year and section, subject code and description, and unit information.
4. Multi-level student table headers with sequence, student number, student name, grouped assessments, and calculated grade results.
5. Compact summary/criteria information rather than dashboard cards.

The implementation preserves this hierarchy while retaining CDM Portal's forest-green, emerald, academic-gold, and semantic theme tokens.

## Previous UI versus aligned UI

Before Grade Entry 4.0, Grade Entry 3.0 already provided a compact title, period tabs, one action toolbar, a grouped assessment spreadsheet, and a criteria panel. Its class identity still appeared mainly as a web application heading, and student number/name shared one sticky column. Calculated output was limited to one period-oriented result column.

Grade Entry 4.0 adds:

- A reusable institutional gradesheet header with the approved CDM seal.
- Verified institutional name and location from the existing institution profile.
- An explicit Professor working-copy and non-official-record label.
- Sheet-like academic metadata cells instead of summary cards.
- Course/program, year/section, term, subject, total units, and institute where supplied by the workspace.
- A compact entry instruction and current period/status/deadline strip.
- Separate sticky sequence, student-number, and student-name columns.
- Multi-row category, assessment, and calculated-result column groups.
- A calculated results group containing the supported Midterm, Finals, final grade, grade-point equivalent, and remarks values.
- Stronger gridlines, tabular numerals, denser rows, gold group accents, and visibly read-only calculated surfaces.

## Component arrangement

`RegistrarGradesheetHeader.vue` owns the institutional and academic heading. `GradingView.vue` remains the authoritative interaction workspace and presents the following website Professor sequence:

1. Compact Grade Entry title, return action, and Grade Entry / Grade Submission workflow navigation.
2. Institutional gradesheet header.
3. Midterm / Finals / Final Grade navigation.
4. Search and assessment-management toolbar.
5. Save status/action strip.
6. Spreadsheet on the left and criteria panel on the right at suitable desktop widths.

Grade Submission remains a separate route and component. No Submission Readiness panel was returned to Grade Entry.

## Metadata and column compatibility

| Registrar-inspired field | CDM Portal mapping | Status |
|---|---|---|
| Institution and seal | Existing institution profile and approved shared seal | Supported |
| Course/program | `workspace.class.program` | Supported |
| Institute | `workspace.class.institute` | Supported when present |
| Year and section | `year_level` and `section` | Supported |
| Semester and academic year | Existing class term fields | Supported |
| Subject code and description | Existing subject fields | Supported |
| Total units | Existing `units` value | Supported |
| Lecture/laboratory unit split | Not returned by the grading workspace | Not displayed |
| Sequence number | Current filtered roster order | Supported |
| Student number and name | Authorized roster fields | Supported |
| Assessment categories and items | Current active assessment records | Supported and dynamic |
| Midterm and Finals | Existing readiness calculation results | Supported |
| Final grade | Existing authoritative final calculation | Supported |
| Equivalent grade | Existing configured `grade_point` | Supported; labeled Equivalent |
| Remarks | Existing configured grading-scale remark | Supported |
| Preliminary period | No such grading period in the current model | Not introduced |
| Contribution columns | No independent supported output fields | Not fabricated |
| Separate rounded grade | No separate supported field | Not fabricated |

The interface labels calculated results as read-only and labels the entire sheet as a Professor working copy. An em dash means the current response contains no value; it is not presented as an official grade.

## Spreadsheet and criteria behavior

- Assessment groups and labels come exclusively from active assessment records for the selected Midterm or Finals period.
- The table uses `scope="colgroup"` for Student Information, each assessment category, and Calculated Results.
- Sequence, student number, and student name remain sticky while the assessment and result columns scroll horizontally.
- Score cells retain borderless inactive inputs, one focus ring, native-spinner suppression, maximum-score validation, dirty state, and Enter-to-next-student behavior. Browser Tab and Shift+Tab behavior is unchanged.
- One Save changes action still processes all pending score batches. Successfully confirmed batches are removed from drafts individually, preserving accurate partial-failure reporting.
- Edit Columns, assessment naming, archival confirmation, Bulk Entry, period locking, and unsaved-navigation protection remain unchanged.
- Criteria continues to show the current period's actual category records, aggregate available maximum scores, configured weights, and total weight. It continues to use the existing period-weight endpoint.

## Responsive and theme behavior

- Desktop uses the complete institutional header and keeps spreadsheet/criteria side by side where available space permits.
- Academic metadata changes from four columns to two below 900px and one at 390px.
- The criteria panel moves below the spreadsheet below the existing 840px breakpoint.
- The detailed grid remains a horizontally scrolling sheet rather than transforming into cards.
- Mobile keeps practical score widths and compact institutional identification.
- Light, Dark, and System appearances use existing semantic surface, text, border, accent, warning, success, and danger tokens.
- No authenticated navigation geometry, connected active-selection animation, or route transition was changed.

## Files added

- `CDM_Frontend/src/modules/grading/RegistrarGradesheetHeader.vue`
- `CDM_Frontend/test/registrarGradesheetAlignment.test.js`
- `docs/UI_2_REGISTRAR_GRADESHEET_ALIGNMENT.md`

## Files modified

- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/test/gradingSpreadsheet.test.js`

## Validation results

- Focused Grade Entry 4.0 regression set: passed (6 files), including header structure, dynamic assessment groups, calculated read-only results, sticky/responsive styling, Grade Submission separation, and existing spreadsheet behavior.
- Full frontend suite: passed, 62 test files with 0 failures.
- Website production build: passed with `VITE_API_BASE_URL=https://example.invalid/api` as an inert build-time HTTPS placeholder. No environment file was changed.
- Android mobile-development build: passed with `npm run mobile:development:build`.
- Electron renderer build: passed with `npx vite build --mode desktop-development`.
- `git diff --check`: passed with no whitespace errors.
- Theme-token regression: passed after replacing literal header colors with the existing semantic accent and on-accent tokens.

The builds verify compilation and platform gating; they are not a substitute for rendered browser/device comparison. No browser screenshot tooling or primary Registrar photograph was available in this session.

No database command is required or authorized for this work.

## Manual screenshot and UAT checklist

1. Attach or provide local paths for both reference images, particularly the real Registrar gradesheet photograph.
2. Open a real assigned class as a Professor on the website at 1440px.
3. Capture Grade Entry and compare the institutional block, academic field order, vertical spacing, group-header depth, column proportions, and summary sequence against the primary photograph.
4. Repeat at 1024px and confirm the sheet receives priority over the criteria panel.
5. Switch Midterm and Finals and confirm assessment categories, assessment columns, criteria data, period status, and deadlines change from real data.
6. Confirm Student Information contains separate sequence, student-number, and student-name columns and remains frozen during horizontal scrolling.
7. Confirm Calculated Results displays only supported values and every calculated cell is visually distinct from editable score cells.
8. Enter scores using mouse, Tab, Shift+Tab, and Enter. Confirm only the active cell has a visible selection outline.
9. Verify dirty, invalid, maximum-score, successful save, and partial-failure feedback.
10. Exercise Add Assessment, automatic/custom labels, Bulk Entry, Edit Columns, rename, and archival confirmation.
11. Edit authorized criteria weights and confirm total and persistence after reload.
12. Navigate to Grade Submission with clean and dirty score states; verify the separate route and unsaved-change protection.
13. Repeat Grade Entry at 768px, 390px, and 320px without page-level horizontal overflow or clipped save actions.
14. Repeat in Light, Dark, and System themes and check contrast of gridlines, editable cells, calculated cells, gold accents, and validation states.
15. Confirm Student, Guest, Registrar, Admin, Android, and Electron do not receive the website Professor gradesheet presentation.

Visual comparison remains pending until the missing reference images are supplied. No exact-match or percentage-fidelity claim is made from source inspection alone.
