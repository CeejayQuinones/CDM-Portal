# CDM Portal Enrollment UI 2.0 — Phase 1.2

## Reference and scope

- Reference repository: `https://github.com/ayumiswift16-ux/ENROLLMENT`
- Inspected revision: `b67543eb30c69548694659689f4e51a1b9d3dcb0` (`main`)
- Primary source: `src/pages/Enroll.tsx`
- Target: Website Student Enrollment on local `dev`

The implementation adapts the collaborators' actual three-step organization into Vue 3 and CDM Portal UI 2.0. It retains Laravel/Sanctum authorization and the existing versioned Enrollment workflow. React, Firebase, static course capacity, and direct client persistence were not imported. The three-step markup is selected only when `VITE_CDM_CLIENT=web`; the shared Android path retains its prior four-stage content and controls.

## Original reference inventory

1. **Student info — Personal details:** first/middle/last name, birthday, derived age, gender, year level, province, city, barangay, street, full address, Student ID, contact, email, and four first-year image documents.
2. **Enrollment — Select type:** Regular, Irregular, Transferee, and Returnee cards.
3. **Course — Academic path:** static institute/program cards, primary and first-year secondary program choices, hard-coded slots, review, and confirm/update action.

Reference status presentation uses Not Started, Validating, and Enrolled in the component. Its client writes directly to Firestore. CDM Portal instead retains `draft`, `submitted`, `under_review`, `approved`, `enrolled`, `rejected`, and `cancelled` with server-authoritative transitions.

## Implemented workflow

### Step 1 — Student info

- Presents the authenticated account's existing first, middle, and last name, birth date, derived age, gender, contact number, email, address, and academic year level as read-only verified information.
- Labels the reference-required and optional distinctions without creating a new Enrollment validation policy.
- Shows province, municipality, and barangay positions transparently as unavailable because the current Profile stores one combined address and has no verified dependent-address dataset.
- Places configured Enrollment document tiles in the same first step. Uploads use the existing versioned Laravel multipart endpoint, accepted PDF/JPEG/PNG types, 10 MB backend limit, and verified-document reuse.
- Mentions Form 137/Summary of Grades, Good Moral, PSA Birth Certificate, and 2×2 photo only as reference equivalents that appear when staff configure matching active document types.

### Step 2 — Enrollment

- Retains the four supported classification cards and exact backend values.
- Saves classification through the existing application mutation endpoint.
- Refreshes configured requirements after save.
- Preserves unsaved-classification route and browser-leave protection.

### Step 3 — Course

- Shows the accepted Admission or assigned Student primary program as the verified selected card.
- Shows curriculum, year level, and enrollment classification from authoritative data.
- Shows a clear unavailable secondary-program card instead of an inert or fake choice.
- Includes final term/program/classification/document readiness review and the existing confirmation dialog.
- Submission remains disabled for an unsaved classification, closed/ineligible state, or missing required configured documents.

## Existing application and status behavior

- Status and paginated application history load before draft creation.
- An existing current application is opened instead of duplicated.
- Drafts reopen at Student info with saved classification and real configured requirements.
- Submitted and review states remain read-only.
- Success is shown only after the authorized Laravel response.
- Enrollment finalization, subject assignment, Student-number creation for admitted Guests, and COR remain separate authorized staff/academic operations.

## Intentionally omitted fields and backend gaps

| Gap | Required contract before implementation |
| --- | --- |
| Editable Enrollment-owned personal data | Ownership decision plus validated profile update endpoint, conflict/version rules, audit requirements, and authorization |
| Separate province/city/barangay values | Schema/API mapping, authoritative geographic dataset/version, dependent-option endpoint or packaged verified dataset, and migration approval |
| Primary/secondary program choices | Application schema fields, active program catalog endpoint, first-year policy, duplicate-choice validation, Admission handoff reconciliation, capacity semantics, audit trail, and Registrar decision workflow |
| Replace/remove Enrollment uploads | Authorized delete/replace endpoint, version requirement, storage cleanup transaction, audit event, and verified-document reuse rules |
| Browser upload progress | Upload adapter progress contract; current API exposes busy/success/error states only |
| Fixed four first-year requirements | Registrar/academic policy approval; current period/classification configuration remains authoritative |

No frontend-only persistence substitute was created.

## Visual and responsive decisions

- Connected three-step indicator uses the existing accessible WebsiteStepper.
- Personal information uses reference-like grouped field surfaces without presenting read-only data as editable inputs.
- Document tiles remain alongside Student information.
- Enrollment cards retain their four-column desktop hierarchy and responsive collapse.
- Course cards distinguish verified selection from unsupported secondary choice.
- New CSS is scoped under `html.website-ui-2-client`; Android and Electron do not receive the presentation rules.
- Personal fields collapse from three columns to two below 900 px and one below 600 px. Program choices collapse to one column below 600 px.
- Existing Light/Dark/System semantic tokens and reduced-motion behavior are retained.

## Files changed

- `CDM_Frontend/src/modules/enrollment/EnrollmentStudent.vue`
- `CDM_Frontend/src/modules/enrollment/enrollment.css`
- `CDM_Frontend/test/enrollmentFoundation.test.js`
- `CDM_Frontend/test/enrollmentReferenceParity.test.js`
- `CDM_Frontend/test/enrollmentPhaseOneTwo.test.js` (new)
- `docs/ENROLLMENT_REFERENCE_PARITY.md`
- `docs/UI_2_ENROLLMENT_PHASE1_2.md` (new)

The previously completed COR null-schedule repair remains in `EnrollmentCor.vue` and its existing regression test; it was not redesigned in Phase 1.2. Professor Grading was not modified.

## Recovery checkpoint

Pre-change recovery is stored at `.local-recovery/enrollment-ui2-phase1-2-prechange-20261028/`:

- `tracked.patch` SHA-256: `d8f6740319bcf0976859623cd08afa47ad0ccfacb6bbb5085d2f1f8183bf36ea`
- `untracked.tar.gz` SHA-256: `300d4413d567bfc552a41cb2f18d86ea76d15092236fe3f40527782b66933257`

The archive was listed successfully after creation. Existing stashes and earlier recovery checkpoints remain untouched.

## Validation results

| Check | Result |
| --- | --- |
| Focused Enrollment, COR, reference-parity, platform-isolation, and frozen-Grading checks | Passed: 5 files, 0 failures, 1.967 seconds |
| Full frontend suite | Passed: 72 files, 0 failures, 8.036 seconds |
| Website production build with inert `https://example.invalid/api` build-time URL | Passed: 356 modules, 12.01 seconds |
| Android mobile-development build | Passed: 350 modules, 10.87 seconds; PWA generated |
| Electron desktop-development renderer build | Passed: 350 modules, 11.29 seconds; PWA generated |
| `git diff --check` | Passed with no whitespace errors |

No Laravel contract was changed, so Laravel tests were not required. No MariaDB, migration, seeder, or `.env` operation was used.

## Manual screenshot and workflow UAT

1. Capture the collaborators' `/enroll` and CDM Portal `/enrollment/status` at matching 1440, 1024, 768, 390, and 320 px widths.
2. Compare title, three connected steps, Student-info field sequence, document placement, four classification cards, program cards, and footer actions—not reference colors or navigation.
3. Verify real profile values and explicit unavailable values in Light, Dark, and System themes.
4. Verify keyboard traversal, focus-visible states, radio selection, Previous/Continue, confirmation focus, and browser-leave warning.
5. Test an empty requirement set, four configured reference-equivalent requirements, optional requirements, verified reuse, valid upload, invalid type, over-10-MB upload, and server failure.
6. Change classification, save, return to Student info, and verify requirements refresh without losing the application.
7. Verify current draft reuse after refresh and no duplicate creation request.
8. Verify upcoming, closed, submitted, under-review, approved, enrolled, rejected, and cancelled presentations with authorized fixtures.
9. Verify CDM Buddy does not obscure upload or footer actions at 390 and 320 px.
10. Recheck COR with scheduled, unscheduled, and null-time subjects, then print preview.
11. Confirm Android and Electron retain their prior presentation.

No interactive screenshot comparison was available during implementation; visual acceptance remains manual.

## Registrar Phase 2 candidates

- Review whether configured document types need aliases matching the collaborators' four labels.
- Decide whether personal/profile corrections belong in Settings, Admission reconciliation, or a versioned Enrollment correction request.
- Define whether program preferences are institutionally valid and who approves them.
- If approved, design the program-capacity source, secondary-choice decision rules, and Registrar reconciliation UI before exposing Student controls.
- Define audited document replacement/removal behavior.

