# Emerald Academic UI — Website Step 1

## Scope and isolation

The Step 1 presentation layer is activated only when `VITE_CDM_CLIENT=web`. `DashboardLayout` adds `website-academic-shell` and the document-level `website-academic-client` hook only for the web client. Capacitor mobile and Electron desktop builds load the CSS file but do not receive either activation class, so their existing presentation remains unchanged.

No API request, validation rule, calculation, authorization rule, route, or database schema was changed. CDM Buddy remains mounted once outside the routed page transition.

## Website route audit

Redesigned existing website surfaces:

- Student: dashboard-adjacent module pages, Enrollment status and academic schedule/COR, published grades, Academic Monitoring, AI Help, notices and study plans, Document Requests and appointment workflow, and Student Settings/record requirements.
- Guest: Admission application, status, entrance examination, result/recommendation, application documents exposed by the existing Admission workflow, and admitted-Guest Enrollment routes.
- Professor: remaining dashboard surfaces, assigned classes and teaching schedule, class rosters, grade entry, bulk assessments, grade messages and submission, Academic Monitoring adviser alerts/study plans where authorized.
- Shared website presentation: forms, validation feedback, tabs, status badges, loading/empty/error states, wide academic tables, and modal surfaces.

Unavailable existing website modules:

- Guest Profile/Settings has no authorized route.
- Professor Profile/Settings has no authorized route.
- Student-facing Student Records pages do not exist; the current Student Management/physical-record routes are staff-only and therefore remain part of the Electron presentation.

No replacement modules were created for unavailable routes.

## Visual system

- Emerald hierarchy with warm academic-gold accents and low-contrast grid canvas.
- Shared paper surfaces, border, shadow, focus, feedback, badge, tab, and control treatments.
- Sticky headers for wide academic tables, horizontal containment, row focus feedback, and clear editable grade inputs.
- Responsive cards and forms with 44 px touch targets at narrow website widths.
- Web-only modal backdrop and panel treatment, preserving each workflow's existing focus and dismissal behavior.
- 140–160 ms control/table transitions and existing 170–260 ms navigation/page transitions.
- Reduced-motion overrides for the complete routed content tree.

## Manual UAT

- At desktop, tablet, 390 px, and 320 px website widths, verify there is no page-level horizontal overflow. Wide tables should scroll inside their own container.
- Student: submit and review Enrollment, inspect schedule and COR, change grade term, open a grade conversation, use Monitoring and AI Help, create and track a document request, book/cancel an appointment, upload a record requirement, and save each editable Settings section.
- Guest: resume Admission, upload required documents, inspect application status, complete an entrance examination without accidental dismissal, and inspect results/recommendations when authorized.
- Professor: search assigned classes, inspect rosters/schedules, enter and save assessment scores, preview bulk assessments, submit a grade sheet, and use grade messages.
- Verify editable grade inputs use the highlighted treatment and read-only/disabled cells remain visibly distinct.
- Verify keyboard focus, tab order, Escape behavior, focus restoration, error announcements, loading states, and confirmation dialogs.
- Verify CDM Buddy module greetings, mute persistence, manual opening while muted, drag/dock position, acknowledgement actions, and Settings exclusion.
- Build and open the Capacitor Android and Electron clients and compare them with their pre-Step-1 presentation; neither should receive the web activation classes.
- Confirm Event routes, links, reports, notifications, and permissions remain absent.

Automated screenshot capture is not configured in this repository. Before/after screenshots therefore require local browser UAT with representative role accounts.

## Step 1.1 refinement

- Desktop website navigation is persistent and defaults to an expanded 264 px institutional sidebar. Its account-scoped preference can collapse it to an 84 px icon rail without losing the seal, active-route indicator, profile, or Sign Out action.
- Nested navigation automatically opens its active group. Selecting a group while collapsed expands the rail before exposing the nested links.
- Mobile website navigation remains a temporary safe-area-aware drawer with a backdrop, internal close control, focus containment, Escape handling, focus restoration, route-close behavior, and background scroll locking.
- Dashboard content is capped to readable widths. Summary cards no longer stretch across very wide displays, while the Student and Guest welcome and identity panels use tighter spacing at 390 px and 320 px widths. The previously empty Guest website dashboard now shows only authenticated account details and the server-provided Admission/Enrollment eligibility already present in the session; non-web clients retain their prior placeholder.
- CDM Buddy treats the desktop sidebar, sticky navbar, and Student welcome panel as protected layout regions. It recalculates after sidebar animation, viewport changes, and route transitions while preserving account-scoped drag and dock state.
- All activation state remains tied to `VITE_CDM_CLIENT=web`; mobile and desktop builds retain the non-web sidebar classes and presentation.

Step 1.1 browser UAT should verify expanded and collapsed states with two different accounts, every role-specific nested group, keyboard-only drawer use, background scroll locking, 320 px/390 px dashboard wrapping, and Buddy docking before and after sidebar changes.
