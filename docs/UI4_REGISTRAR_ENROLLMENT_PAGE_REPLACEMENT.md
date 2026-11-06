# UI 4.0 — Registrar Enrollment page replacement

## Source and boundary

The collaborators' [ENROLLMENT repository](https://github.com/ayumiswift16-ux/ENROLLMENT) was inspected at `main` commit `b67543eb30c69548694659689f4e51a1b9d3dcb0`: `src/pages/Records.tsx`, `Enroll.tsx`, `Courses.tsx`, `Dashboard.tsx`, and `src/components/ui/Button.tsx`, `Card.tsx`, `Input.tsx`. Records supplied the compact searchable management-table/detail pattern; Card supplied a bounded header/content/footer hierarchy; Button and Input supplied modest sizes and clear hierarchy. The reference's Firestore data, sample counts, academic fees, and status transitions were not copied. CDM Portal's Laravel data and role rules remain authoritative.

This pass changes the actual `EnrollmentStaff.vue` content for `/enrollment/applications` and `/enrollment/periods`. The Desktop UI 3.0 72px rail, contextual navigation, Website Student Enrollment, Android presentation, and Professor Grading are outside the change. Styling is scoped to `html.cdm-desktop-client .ui4-staff` in `registrarEnrollmentUi4.css`, using the existing `--desk-*` light/dark/system tokens.

## Structural before/after

| Rejected layout | Replacement and interaction | Source |
| --- | --- | --- |
| Duplicate Applications/Periods buttons inside page content | Removed. Existing contextual navigation remains the only section navigation. | `EnrollmentStaff.vue` |
| Six permanently visible application filters | Applicant search, sort, status tabs, and Clear in one toolbar; Course/Classification/Status/Term in an expandable filter panel. Status tabs use supported backend status values; the result total comes from the current paginated API response. | `EnrollmentStaff.vue` |
| Wide legacy table and oversized Inspect buttons | Bordered records workspace with applicant identity, program/term, classification, status, compact Review action, selected/hover state, empty state, and integrated pagination. | `EnrollmentStaff.vue`, `registrarEnrollmentUi4.css` |
| Plain Inspect modal | Wide two-column application review workspace: identity/path and submitted requirements on the left; status, notes, and authorized decisions on the right. Cancelled history stays inspectable. Existing confirmation and versioned review endpoints remain. | `EnrollmentStaff.vue` |
| Academic-year and period tables stacked without relationship | Selectable academic-year list and contextual enrollment-window list. Each window shows term, actual state, and opening/closing times. Only loaded page data are shown; pagination remains explicit because the backend returns periods in pages of 20 without a year filter. | `EnrollmentStaff.vue` |
| Inline year/period forms | Focus-managed dialogs using existing `EnrollmentDialog`; grouped fields, classification requirements, server error feedback, and explicit confirmation before enabling/disabling a period or changing a year's active/completed state. | `EnrollmentStaff.vue` |
| Inconsistent large green buttons | Registrar-specific primary, outline, ghost, and destructive control classes with compact height, disabled, hover, focus, and loading states. No global `button` override. | `registrarEnrollmentUi4.css` |

Existing API paths and request contracts are unchanged: GET `/enrollment/applications` and `/enrollment/periods`; GET application detail; POST versioned review/approve/reject; GET document downloads; POST/PUT academic years; POST/PUT enrollment periods. Filters, sorting, pagination, review notes, documents, and period document requirements remain connected to these endpoints. No backend business rule or schema was changed.

## Other Registrar Enrollment pages — backlog, not claimed complete

- `/enrollment/sections` → `EnrollmentAcademicStaff.vue`: audit section creation, capacity visibility, roster detail, and scheduling handoff; replace content after screenshot approval of these two pages.
- `/enrollment/subject-management` → `EnrollmentSubjectManagement.vue`: audit subject search, prerequisite editing, and validation feedback.
- `/enrollment/scheduling` → `EnrollmentAcademicStaff.vue`: audit day/time/room conflict presentation and assigned-professor workflow.
- `/enrollment/records` → `EnrollmentAcademicStaff.vue`: audit record search/detail, COR action, and pagination.

## Validation and visual acceptance

Focused Registrar/Enrollment tests passed. Full frontend suite: **78/78 test files passed**. Website production build passed with inert `VITE_API_BASE_URL=https://build.invalid/api`; Android mobile-development build passed; Electron renderer production build passed with inert `VITE_DESKTOP_API_BASE_URL=https://build.invalid/api`. `git diff --check` passed. The pre-existing `import.meta` warning in a CommonJS test harness is non-fatal. No Laravel or database command was run for this presentation-only change. The previously proposed Enrollment reapplication migration remains unapplied.

Rendered screenshots could not be captured in this environment: no Chromium, Chrome, Playwright browser, or Xvfb executable is available. **Visual acceptance is pending**, not inferred from passing tests. Capture and compare populated Applications, filtered Applications, application review, academic-year list, and period form at 1366×768, 1600×900, 1920×1080, and a narrow resizable Electron window. Test Light, Dark, and System; keyboard focus and Escape; long names; empty/loading/error states; window enable/disable confirmation; and historical cancelled records. Verify no overlap with the 72px rail or contextual panel.

## Recovery

Before editing, the dirty `dev` worktree was checkpointed at `/tmp/cdm-ui4-registrar-enrollment-prechange.SQOYYR`. SHA-256: `tracked.patch` = `c38146d58c4f7e678a3e791f72de3a4e0c52a205a07af727e2c418f5f3c7d8eb`; `untracked.tar.gz` = `a65691c7046ccf344aec9b5c3783d7998deced38585bf2aa311b37fab39a0ad9` (172 archived paths, excluding pre-existing `.local-recovery` archives). Three existing stashes and prior checkpoints were not altered.
