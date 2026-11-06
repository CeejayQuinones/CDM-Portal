# Registrar Enrollment UI Polish Pass

## Scope

This pass refines the current Electron Registrar Enrollment redesign without restoring the old layout. It covers Sections, Subjects, Scheduling, and Enrollment Records. Applications and Enrollment Periods remain unchanged.

No API, route, permission, enrollment rule, COR workflow, backend file, database schema, or dialog action was changed.

## Recovery checkpoint

- Path: `/tmp/cdm-registrar-enrollment-polish-prechange.kauJKa`
- `tracked.patch`: `9725291d113284f2faec559f248543aa215ddd93bbe6a52c9c1b5f9ce944d8ab`
- `untracked.tar.gz`: `3677d00ab6a69e76bca55460f65bae3cb082c0df49a26be92d0c98ec4028d24a`
- Both files were hashed after the archive completed and verified.

## Visual and structural refinements

### Shared toolbars

- Search now receives the largest share of horizontal space.
- Secondary controls are labeled **More filters** and remain collapsed until requested.
- Toolbar padding, gaps, control heights, borders, and radii were reduced.
- Secondary buttons use lighter surfaces and subtler hover treatment.
- Reduced-motion behavior remains explicit.

### Sections

- Rows are shorter and use three stable areas: section identity, capacity, and status/action.
- Program, year, and term form a single readable hierarchy beneath the section name.
- Capacity uses a thinner progress indicator and shorter labels.
- Status and Edit are aligned together at the row edge.
- Create section remains the primary page action.

### Subjects

- Curriculum and semester grouping remains the primary catalog structure.
- Subject code/state, title/hours, prerequisite/counts, and actions use four balanced columns.
- View is now a light action; less-frequent actions remain under Manage.
- Row spacing and group headers are lighter, with subtle hover feedback.

### Scheduling

- Desktop Scheduling now opens on **Timetable** when a section is selected; Website and Android continue to default to Cards.
- The selected view now receives a real active style as well as `aria-pressed`.
- A compact context strip shows curriculum, curriculum-subject count, and section status.
- The weekly timetable is the main visual surface.
- Timetable blocks suppress crowded row-action buttons; Cards and List retain every Edit, Copy, Delete, and Add Schedule action.
- A short hint explains where individual entries are managed.
- The no-section and configuration empty states remain intact.

### Enrollment Records

- Each record now uses four explicit areas: student identity, academic context, classification/status, and COR action.
- Classification and status are grouped into a balanced badge cluster.
- Program/section/term values truncate cleanly instead of colliding with actions.
- View COR and unavailable states remain tied to the existing record status rules.

### General presentation

- Softer borders, lighter cards, smaller badges, reduced vertical whitespace, and 140 ms hover transitions improve rhythm without introducing layout motion.
- Every new or changed presentation selector remains rooted at `html.cdm-desktop-client`.
- The compact icon-only navigation rail and module context panel were not modified.

## Files changed

- `CDM_Frontend/src/modules/enrollment/EnrollmentAcademicStaff.vue`
- `CDM_Frontend/src/modules/enrollment/EnrollmentSchedule.vue`
- `CDM_Frontend/src/modules/enrollment/EnrollmentSubjectManagement.vue`
- `CDM_Frontend/src/modules/enrollment/registrarEnrollmentComplete.css`
- `CDM_Frontend/test/registrarEnrollmentCompleteRedesign.test.js`
- `docs/UI4_REGISTRAR_ENROLLMENT_POLISH.md`

## Validation

- Focused frontend regression set: 8 test files passed.
- Full frontend suite: 80 test files passed, 0 failed.
- Website production build: passed in 12.21 seconds.
- Android mobile-development build: passed in 12.27 seconds.
- Electron renderer build: passed in 11.41 seconds.
- `git diff --check`: passed.

Website and Electron builds used the inert compile-time URL `https://build.invalid/api`. No hosted service or database was contacted.

## Manual visual UAT still required

Screenshot acceptance was not available in this environment. Review the rendered Electron application before visual approval:

1. At 1366×768, 1600×900, and 1920×1080, open all four Enrollment destinations in Light and Dark themes.
2. Verify primary search remains prominent and More filters expands without shifting or clipping the context panel.
3. Confirm long section, program, curriculum, subject, and student names truncate cleanly and remain available through their native title text where provided.
4. Verify section capacity indicators and status/actions align across short and long rows.
5. Confirm Subject Manage menus remain above adjacent rows and every action targets the correct subject.
6. Select sections with early, late, overlapping, and unscheduled meetings; compare the timetable against stored schedule data.
7. Switch Scheduling between Timetable, Cards, and List and exercise all existing actions from Cards/List.
8. Resize the Electron window narrowly and verify the timetable scrolls horizontally while all filters and actions remain reachable.
9. Search Enrollment Records with each filter combination, open COR, and confirm print preview is unchanged.
10. Confirm the icon-only rail and Enrollment context panel do not move, widen, or become obscured.

No claim of final visual acceptance is made until this screenshot and interaction review is completed.

## Filter toolbar alignment correction

The Scheduling subject search and Enrollment Records search toolbars received a targeted alignment correction after the polish pass:

- Both now use the reusable Electron-only `en-search-toolbar` grid.
- Search, More filters, and Reset share a dedicated `en-toolbar-actions` container.
- Toolbar inputs, selects, and actions use a consistent 40px height and 10px grid gap.
- Search fields expand while action buttons retain their intrinsic widths.
- Enrollment Records uses a three-column search/context/actions layout at wide widths.
- Scheduling uses a two-column search/actions layout alongside the existing section selector.
- At intermediate widths, Record actions move to their own aligned row; below 700px both toolbars become single-column and actions wrap naturally.
- The Scheduling primary-button selector is limited to the submit button so More filters and Reset retain their secondary styling.

Validation for this correction:

- Focused Enrollment/Desktop/platform tests: 6 test files passed.
- Electron renderer build: passed in 12.32 seconds using `https://build.invalid/api` only as an inert build-time URL.
- `git diff --check`: passed.
- Rendered verification at 1366px and narrow Electron widths remains pending because no GUI screenshot harness was available.
