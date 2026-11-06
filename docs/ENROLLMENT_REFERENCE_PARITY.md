# Enrollment Reference Parity Blueprint

## Audit source

- Repository: `https://github.com/ayumiswift16-ux/ENROLLMENT`
- Inspected revision: `b67543eb30c69548694659689f4e51a1b9d3dcb0` (`main`)
- Read-only checkout: `/tmp/cdm-enrollment-reference-20261028`
- Audit date: 2026-10-28

The inspected source is a React 19 / TypeScript / Vite 6 application using Firebase Authentication and Firestore directly from the client. Its small Express server serves Vite/static files and sends email verification codes. Capacitor and Electron wrapper manifests are present. Tailwind 4, Motion, Lucide, React Hot Toast, Recharts, JSZip, and Firebase are declared dependencies.

The repository contains no automated test files or test script. Its README claims a Laravel 11 backend under `laravel-backend`, but that directory and the documented Laravel routes/migrations are absent at the inspected revision. The current source is therefore authoritative over that stale README description.

## Actual route and role inventory

| Reference route | Source page | Effective source access | Actual purpose | CDM Portal mapping | Classification |
| --- | --- | --- | --- | --- | --- |
| `/login`, `/register`, `/create-account` | `Login.tsx` | Public | Firebase login and Student/Professor registration | Existing Portal auth/Guest registration | Not applicable; Portal authentication remains authoritative |
| `/dashboard` | `Dashboard.tsx` | Authenticated role dashboards | Role-specific summaries and shortcuts | Existing role dashboards | Not part of Student Enrollment port |
| `/enroll` | `Enroll.tsx` | Student only in `App.tsx` | Three-step Student application, documents, status, finalized form | `/enrollment/status` via `EnrollmentStudent.vue` | Supported but requiring UI adaptation |
| `/steps` | `Steps.tsx` | Student | Six informational admission/enrollment cards | Existing Admission and Enrollment status guidance | Partially applicable; informational only, and its “Learn Requirements” button has no handler |
| `/courses` | `Courses.tsx` | Student | Static program cards and slot counts | Portal course/curriculum data | Requires a new approved read contract for public program/slot browsing; not ported |
| `/scheduling` | `Scheduling.tsx` | Student/Professor | Card/timetable/list schedules; Professor editing paths | Existing Student schedule and Electron Registrar scheduling | Existing supported views retained; no Website Registrar capability added |
| `/records` | `Records.tsx` | Professor only in current router | Large record/validation UI containing Student and faculty operations | Electron Registrar records and existing Professor roster | Administrative functions are not authorized for Student Website; Electron work is out of scope |
| `/settings` | `Settings.tsx` | Admin only | General, enrollment schedule, security, notifications, maintenance | Existing Portal Settings and Electron administration | Not part of Student implementation |

The reference contains several Admin branches inside `Enroll.tsx` and `Records.tsx`, but the current router does not expose `/enroll` or `/records` to Admin. They are documented as source code paths, not claimed as reachable behavior.

## Reference Student Enrollment inventory

### Page hierarchy

`Enroll.tsx` uses a maximum-width centered workspace with:

1. qualification badge, `New Student Enrollment` title, description, and `Step N of 3`;
2. horizontal three-step progress indicator;
3. one large form panel;
4. Previous/Continue or Confirm action footer;
5. separate access-blocked, success, read-only finalized, and Official Registration Form states.

### Step 1 — Student info / Personal details

Field order and source validation:

| Order | Field | Source required? | Source behavior | Portal support |
| ---: | --- | --- | --- | --- |
| 1 | First Name | Yes | Editable text | Identity already held in Admission/User Profile; not editable by Enrollment |
| 2 | Middle Name | No | Editable text | Existing profile only; not an Enrollment field |
| 3 | Last Name | Yes | Editable text | Existing profile only; not an Enrollment field |
| 4 | Age | Derived | Calculated from Birthday | No Enrollment write contract; not duplicated |
| 5 | Gender | Yes | Male/Female/Other select | Profile/Admission concern; not duplicated |
| 6 | Birthday | Yes | Date input | Profile/Admission concern; not duplicated |
| 7 | Year Level | Yes | 1st–4th Year | Server-authoritative Student/admission handoff |
| 8 | Province | Yes | Searchable dependent select | No Enrollment application field |
| 9 | City/Municipality | Yes | Depends on Province | No Enrollment application field |
| 10 | Barangay | Yes | Depends on City | No Enrollment application field |
| 11 | House No/Street | Yes | Text | No Enrollment application field |
| 12 | Full Address | Derived | Concatenated read-only value | No Enrollment application field |
| 13 | Student ID | Admin-only/edit-disabled for Student | Registrar assigned | Portal allocates official Student identity during approved finalization |
| 14 | Contact Number | Yes | Editable text | Existing Profile value displayed through status context; no Enrollment update |
| 15 | Email Address | Yes | Auto-filled from auth, editable | Existing authenticated Profile; no Enrollment update |

For first-year Students only, the reference requires four image uploads: Summary of Grades, Good Moral Certificate, Birth Certificate, and 2x2 Student Photo. It accepts `image/*`, rejects raw files above 10 MB, compresses them in-browser, and stores base64 data in Firestore.

CDM Portal instead uses period- and classification-configured `DocumentType` requirements, accepts PDF/JPEG/PNG up to 10 MB, stores files through Laravel, reuses verified Student documents, and revalidates availability transactionally. The exact four names are shown only if staff configured corresponding official document types; they must not be fabricated.

### Step 2 — Enrollment / Select type

Four card choices exist in this order:

1. Regular Student — standard curriculum sequence.
2. Irregular Student — balanced/back subjects or varying loads.
3. Transferee — transfer from another higher-education institution.
4. Returnee — resume after leave or stop-out.

The reference defaults to Regular and displays an explanatory note. CDM Portal supports the same classifications as lowercase enum values and validates them server-side. This is supported and ready to port visually.

### Step 3 — Course / Academic path and review

The reference groups static course cards under ICS, IBE, and ITE. First-year users must choose a first and second program; continuing users choose one. Static cards display a hard-coded slot count. Review shows name, email/contact, Student ID state, year level, first/second choice, classification, and a hard-coded semester label.

CDM Portal deliberately binds program and curriculum to the accepted Admission decision or existing Student academic record. It has no Student-side “first/second program preference” Enrollment contract and must not display hard-coded capacity or semester values. The safe adaptation is a read-only Academic Path panel using authoritative program, curriculum, year level, term, identity, and classification data. Changing this requires academic-rule and backend approval.

### Submission and status

The reference writes a Firestore enrollment document with status `Validating`; its type declares `Pending`, `Approved`, `Rejected`, `Enrolled`, and `Validating`. It permits updating information while Validating and treats Enrolled as read-only. Source labels and transitions are not consistently centralized.

CDM Portal uses versioned states `draft`, `submitted`, `under_review`, `approved`, `enrolled`, `rejected`, and `cancelled`. Submission, review, approval, document completeness, finalization, and concurrent changes are server-authoritative. These existing semantics are retained.

### Finalized registration form

The reference renders and prints an Official Registration/COE containing term, Student identity, program/type/year, course rows, itemized fees, and payment details. CDM Portal already renders a printable Certificate of Registration from finalized `Enrollment`, `EnrollmentSubject`, section, subject, schedule, and Professor records. Financial assessment and official signatures are explicitly not supported by the current Portal contract, so reference fee/payment sections are not copied.

## Reference controls and interaction inventory

- Previous Step, Continue, Confirm & Enroll / Update Info.
- Enrollment-type cards.
- Program cards with first/second-choice selection.
- Four first-year image drop/upload surfaces.
- Print Document for finalized registration.
- Back to Dashboard / View Student Records after success.
- Access-blocked state for upcoming/ended period.
- Read-only finalized warning.
- Loading and toast feedback.

No dedicated field-level validation messages are rendered; an invalid step produces the generic toast `Please fill in all required fields.`. There is no draft-leave warning. CDM Portal should preserve its stronger server validation and explicit errors rather than reproduce these weaknesses.

## Administrative reference inventory

`Records.tsx` contains search by name/ID/email, course filtering, sorting, classification badges/counts, Student/Professor tabs, detailed profile/document preview, Student ID and section assignment, exam scheduling fields, registration-form editing, fee/payment fields, image lightbox/download, and section search. Much of this mixes Admission, Enrollment, records, and finance concerns.

CDM Portal maps Admission exams to its Admission module, Enrollment applications/finalization to Registrar Electron enrollment routes, physical/student documents to Student Records, and schedules/sections to existing academic Enrollment services. No administrative control is moved to the Website, and Electron presentation is unchanged in this stage.

## Backend comparison

| Capability | Reference implementation | CDM Portal authority | Decision |
| --- | --- | --- | --- |
| Authentication | Firebase Auth/client checks | Laravel Sanctum + role/platform policies | Keep Portal |
| Student application | Direct Firestore `setDoc` | Versioned Laravel workflow and transactions | Keep Portal; adapt UI |
| Identity | Firestore UID/username/email fallbacks | Existing User/Profile/Admission/Student relations | Keep Portal |
| Classification | Four client values | Same four server-validated values | Port card presentation |
| Personal info edits | Embedded in enrollment document | Profile/Admission records | Do not duplicate |
| Program choices | Static list and hard-coded slots | Accepted course/curriculum | Read-only authoritative path |
| Documents | Base64 compressed images | Configured Laravel documents and verified reuse | Use Portal upload contract |
| Period | Firebase settings dates | Active academic-year/semester EnrollmentPeriod | Use Portal |
| Status | Validating/Enrolled-oriented Firestore | Versioned draft/review/finalization states | Use Portal |
| Subjects | Registration-form/static record | Curriculum-derived load and controlled irregular selection | Use Portal Subjects route |
| COR | Printable Firestore form with fees | Finalized academic record; no finance/signatures | Use existing COR |
| Registrar | Mixed source UI | Electron-only Admin/Registrar services | Document only; no Website exposure |

## Page-to-CDM implementation matrix

| Reference page/section | Layout and controls | Existing Portal view/API | Gap and feasibility | Classification |
| --- | --- | --- | --- | --- |
| `/enroll` access state | centered icon, period message, dashboard action | `/enrollment/status`; `/api/enrollment/status` | Data fully supported; visual adaptation safe | Supported but requiring UI adaptation |
| `/enroll` header/stepper | title, step count, three-step rail | `EnrollmentStudent.vue`; Website stepper | Portal requires four semantic stages because identity/program are read-only and documents depend on saved classification | Supported but requiring UI adaptation |
| Personal Information | 15 fields plus derived address/age | status returns verified name, identity, course, curriculum, year, email/contact | No Enrollment write contract for personal/address data | Requires new backend contract and data-ownership approval |
| First-year uploads | four fixed image uploads | configured requirement list; upload API | Names and required/optional state are staff-configured; supports PDF/JPEG/PNG | Supported but requiring UI adaptation |
| Enrollment Type | four selectable cards | `classification` save API | Exact semantic match | Supported and ready to port |
| Course Selection | static cards, two freshman choices | accepted/current course and curriculum | Student choice would violate current handoff rules | Requires academic-rule approval and new backend contract |
| Review | profile and enrollment summary | status, application, requirements APIs | Authoritative fields available | Supported and ready to port |
| Submit/update | Firestore Validating | versioned save/submit/cancel | Existing workflow is safer and retained | Supported but requiring UI adaptation |
| Finalized form | print COR/COE with fees | `/enrollment/cor`, academic record API | Academic COR supported; fees/payment/signatures absent | Partial; finance requires separate approval |
| `/steps` | six informational cards | Admission and Enrollment guidance | Some claims/actions are static and unverified | Not copied as workflow |
| `/courses` | static programs/slots | internal course configuration | No authorized Student catalog/slot endpoint | Requires new read contract and content approval |
| `/records` | validation/admin operations | Electron Registrar Enrollment/Records | Website exposure prohibited | Not applicable to this Website stage |
| `/scheduling` | timetable/cards/list | `/enrollment/schedule` and academic record API | Existing verified schedule retained | Supported; no rewrite needed for application stage |

## Safe Stage B implementation order

1. Recompose the Website Student application landing and draft header using the reference hierarchy and step-count treatment.
2. Present authoritative identity/program/term information in a high-fidelity Personal/Academic Information review panel without editable fake fields.
3. Port the four classification choices as semantic selection cards using existing values and save endpoint.
4. Present the accepted/current program and curriculum as read-only grouped Academic Path information; explicitly explain why program choice is not available here.
5. Recompose Review and configured document requirements with source-inspired upload tiles while preserving Laravel types, limits, versions, and verified reuse.
6. Preserve existing status history, confirmation dialogs, Subjects, Schedule, COR, authorization, and platform isolation.
7. Add focused structural/state/authorization regressions, then run full frontend and platform builds.

## Deferred authorization gaps

Separate approval is required for any of the following:

- editable Enrollment-owned personal/address fields;
- first/second program preference and capacity/slot selection;
- new public/Student program catalog endpoints;
- financial assessment, tuition/payment recording, or official-signature fields;
- Registrar Website access or changes to Electron administrative presentation;
- fixed document names that bypass staff-configured `DocumentType` requirements;
- changes to Admission examination or conversion rules;
- new Laravel endpoints, migrations, or schema mappings.

## Visual comparison limitations

This blueprint is based on verified source structure and styling. The reference application was not run and no matching browser screenshots were captured in this environment. High-fidelity claims are therefore limited to hierarchy, field/control inventory, grouping, and responsive intent visible in source—not pixel similarity.

## Phase 1.2 implementation update — 2026-10-28

The Website Student draft now follows the reference component's recognizable three-step order: **Student info**, **Enrollment**, and **Course**. The earlier four-stage Portal adaptation was consolidated by placing configured uploads in Student info and final review inside Course. This changes presentation only; the versioned Laravel application remains the sole authoritative workflow.

| Reference field or action | Phase 1.2 Portal presentation | Persistence and policy result |
| --- | --- | --- |
| First, middle, last name | Read-only account-profile values | Existing Auth profile; Enrollment never overwrites it |
| Birthday and derived age | Read-only profile birth date and locally derived display age | No Enrollment write |
| Gender, contact, email | Read-only account-profile values, with Student status fallback for contact/email | Existing Profile/Enrollment status reads |
| Year level | Current Student/application value | Server-authoritative application/academic record |
| Province/city/barangay | Explicitly shown as not separately recorded | Current schema has one address field; dependent fields require an approved profile contract and dataset |
| Street/house/subdivision | Existing combined profile address | Read-only; changes remain a Settings/Profile concern |
| Four reference documents | Labels appear only when equivalent active `DocumentType` records are configured | Existing versioned Laravel upload and verified-document reuse |
| Four enrollment types | Selectable Regular, Irregular, Transferee, Returnee cards | Existing enum values and save endpoint |
| Primary program | One selected verified program card | Accepted Admission or assigned Student program |
| Secondary program | Explicit unavailable-state card | No approved application field/API; not simulated |
| Static slots/capacity | Omitted | No authorized Student contract; no hard-coded values |
| Review and submit | Embedded in Course step with readiness and confirmation | Existing submit endpoint revalidates eligibility/documents transactionally |

The Portal does not enforce the reference application's four fixed first-year document names or secondary-choice rule because neither is an approved CDM Portal Enrollment policy. Registrar-configured requirements and accepted/assigned academic identity remain authoritative.

## Phase 1.3 implementation update — 2026-10-29

The previously deferred Courses Offered and six-stage guidance surfaces are now implemented for authenticated Website Students using Portal data and authorization. A new additive, read-only Student catalog endpoint exposes active programs, active curricula, active subjects, semester grouping, and the active academic year. It deliberately excludes capacity, slots, study mode, descriptions, fees, and inactive records.

The reference's dead **View Curriculum** and **Learn Requirements** controls are now working Portal routes. The Website Student dashboard also consumes the existing Enrollment status response for its period, deadline, current application, and finalized-enrollment summary. The Phase 1.2 three-step form and all server-authoritative status transitions remain unchanged.

See `docs/ENROLLMENT_UI2_PHASE1_3_REFERENCE_PARITY.md` for the API review note, exact file inventory, validation results, unsupported fields, and manual UAT checklist.
