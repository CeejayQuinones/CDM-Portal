# UI 2.0 Professor Grading Focus and Readiness Refinement

## Scope and recovery

This change is limited to the website Professor grading workspace. It does not alter grading calculations, validation rules, readiness data, score persistence, final-weight persistence, submission authorization, Android, Electron, or any backend API.

Recovery checkpoint: `/tmp/cdm-grading-focus.76hA3E`

## Double-outline root cause

The selected score input was receiving presentation from several independent layers:

1. The global `input:focus` rule in `main.css` adds a focus shadow.
2. the website UI 2.0 `:focus-visible` rule adds an outline.
3. the Emerald Academic shared input rule adds another border and shadow.
4. the grading form rule adds its own accent border and shadow.
5. the score-cell wrapper adds the intended spreadsheet focus indicator.

The score input also retained the browser's native number controls because no spreadsheet-specific appearance rule suppressed them.

The repair is narrowly scoped to `.website-professor-grading .grade-score-cell`. Internal score inputs now reset their border, radius, outline, and shadow in normal, focus, focus-visible, and invalid-focus states. The wrapper is the only visible focus owner and uses a two-pixel inset emerald box shadow, which does not change table dimensions or move neighboring cells. Chromium/WebKit spin buttons are removed, and Firefox receives `appearance: textfield`; numeric input semantics and validation remain unchanged.

Global focus styling is unchanged for every other control.

## Submission Readiness layouts

### Minimized by default

The panel starts minimized whenever a different grade-sheet workspace is opened. The compact summary always displays:

- `Submission readiness` heading.
- Live Ready or Incomplete state.
- Live submission-window state.
- Total remaining requirement count.
- Midterm, Finals, and Other counts when each group contains blockers.
- The existing authoritative submission-block reason.
- An accessible expand control.

Counts come from the same grouped readiness errors used by the detailed panel. Unclassified issues remain in the `Other` group and contribute to the total.

### Expanded

The expanded region retains the complete existing workspace:

- Grade-data and submission-window states.
- Every grouped Midterm, Finals, and Other issue.
- Period review shortcuts.
- Final-grade weight fields and `Save final weights` action.
- The authoritative disabled reason.
- The existing `Submit to Registrar` or resubmit action.

Collapsing the panel does not mutate readiness data, score drafts, weight values, or submission eligibility. Expansion state remains stable while changing grading periods or refreshing the same class, and returns to minimized when opening a different grade sheet.

## Animation and accessibility

- The detail region animates between CSS grid rows `0fr` and `1fr` over 280ms with a restrained opacity transition.
- Width is never animated, so the grading workspace, rail, and topbar do not reflow horizontally.
- The summary remains visible throughout the transition.
- Hidden details use `visibility: hidden`, `aria-hidden`, and `inert` so their controls do not remain keyboard-focusable while minimized or while the collapse transition finishes.
- The icon control is a native button with `aria-expanded`, `aria-controls`, descriptive expand/minimize labels and titles, visible focus, and keyboard activation.
- `prefers-reduced-motion` removes readiness transitions through the existing scoped reduced-motion rule.
- Light, Dark, and System appearances use existing semantic surface, text, accent, warning, success, danger, and border tokens.

## Files changed

- `CDM_Frontend/src/modules/grading/GradingView.vue`
- `CDM_Frontend/src/modules/grading/grading.css`
- `CDM_Frontend/src/modules/grading/assessmentPresentation.js`
- `CDM_Frontend/test/gradingColumnRefinement.test.js`
- `CDM_Frontend/test/gradingFocusReadiness.test.js` (new)
- `docs/UI_2_GRADING_FOCUS_READINESS.md` (new)

## Automated validation

- Focused grading regression tests: passed.
- Full frontend suite: passed, 60 test files with no failures.
- Website production build with inert HTTPS build-time API URL: passed.
- Android mobile-development build: passed.
- Electron desktop-development renderer build with inert HTTPS build-time API URL: passed.
- `git diff --check`: passed.

No Laravel or database commands were run because no backend contract or business rule changed.

## Remaining manual UAT

1. At 1440px and 1024px, click a clean, dirty, and invalid score cell. Confirm exactly one square emerald inset outline and no orange/native/input outline.
2. Verify focused cells do not change height or width and adjacent columns do not move.
3. Verify native score spinners are absent in Chrome/Edge/Safari and Firefox while typing, arrow-key behavior, numeric validation, maximum validation, Tab/Shift+Tab, and Enter-to-next-student remain correct.
4. Repeat focus checks in Light, Dark, and System themes.
5. Open a class with Midterm, Finals, and unclassified blockers. Confirm minimized totals match the expanded groups exactly.
6. Change readiness data by saving valid grades or weights and confirm minimized status/counts refresh without expanding.
7. Expand and minimize using mouse, Enter, and Space; confirm the control label and icon change and focus remains visible.
8. Verify the expanded panel retains every issue, weight control, save action, submission button, and disabled reason.
9. Minimize with unsaved scores and unsaved weight values; confirm neither is discarded.
10. Switch Midterm/Finals and refresh the same class; confirm expansion state stays stable. Open another class; confirm its panel starts minimized.
11. Review at 768px, 390px, and 320px for clipping, page overflow, or toolbar obstruction.
12. Enable reduced motion and confirm the panel changes state without a prolonged transition.
13. Open Android and Electron grading builds and confirm their existing presentation remains unchanged.

Automated browser screenshots were unavailable in this environment, so visual confirmation remains part of manual UAT.
