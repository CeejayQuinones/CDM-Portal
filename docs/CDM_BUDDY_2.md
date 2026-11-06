# CDM Buddy 2.2

CDM Buddy is a campus companion shown throughout authorized Student and Guest modules. It is mounted once in the persistent authenticated layout, hidden from Settings and all staff/public interfaces, and is not recreated during ordinary route navigation. It keeps the approved green mountain-explorer artwork and consumes one account-scoped endpoint: `GET /api/portal-notices`.

## Publishing a campus notice

Admin and Registrar Staff can open **Campus Notices** in the Electron desktop navigation. Create a draft, choose its audience, category, priority, publication window, and optional safe Portal route, then preview and save it. A draft can be edited or published. A published notice cannot be edited; archive it when it should no longer be visible.

Admin may manage every category. Registrar Staff may manage general, Enrollment, Admission, Registrar, and academic reminders. Class suspension and school-policy categories are Admin-only. This is enforced by Laravel policies and service checks, not only by the interface.

Class suspensions require a verified institutional or government reference, an affected scope, and start/end times. The notice system never changes classes, attendance, or schedules. Weather data cannot create or imply a suspension.

Messages and references are plain text. Destination links must be relative allow-listed Portal paths under Admission, Enrollment, Academic Monitoring, Document Requests, or Settings.

## Weekday reminders

Enable **Recurring weekday reminder**, select one or more weekdays, set an effective date range, and publish the notice. Weekdays are evaluated in `Asia/Manila`. Draft and archived reminders are inactive. Publication start/end can further limit visibility.

The proposed Wednesday washday-shirt and Saturday institute-shirt examples are not seeded and are not treated as official policy. Authorized staff must verify policy and create them if approved.

## Priority and automatic behavior

The server returns messages in this order:

1. Active verified class-suspension notices.
2. Urgent official campus notices.
3. Enrollment and Admission deadlines or account updates.
4. Account-scoped Academic Monitoring reminders.
5. Weather context.
6. Published recurring reminders.
7. Generic informational guidance.

After each eligible module transition, Buddy opens a short module-specific message after about 750 milliseconds. Dashboard, Enrollment, grading, Academic Monitoring, Document Requests, and Admission use a centralized route-to-guidance map. Query changes and nested routes within the same module do not replay the greeting. Rapid navigation cancels stale timers, and a short per-module cooldown prevents route churn from becoming message spam. The first meaningful pointer, touch, or keyboard interaction may trigger the pending message earlier. Basic guidance does not wait for the notice request; a relevant published official notice is preferred when one is already available.

A routine bubble minimizes after about ten seconds. Its timer pauses while hovered, focused, interacted with, or while the page is hidden. Urgent messages do not auto-minimize. Notices requiring acknowledgement remain open until acknowledged, but the mascot remains available for manual reopening. Automatic presentation waits while the user is editing a field, using a modal, or taking an entrance exam.

The bell button mutes automatic bubbles, including route greetings. It does not mute audio—Buddy has no audio—and does not read, acknowledge, or hide official notices. Clicking the mascot still opens the notice list while muted. The preference is stored under an account-specific local key and is restored after reload without carrying into another account. Unmuting schedules the current module's contextual greeting again.

Seen state is scoped to the signed-in account and browser session. It is not a permanent read receipt. Required acknowledgements are saved server-side per notice and user.

## Positioning and animation

Drag the mascot with a mouse, touch, or stylus. A movement threshold distinguishes dragging from a tap, and pointer capture keeps the gesture stable. The mascot clamps to the current visual viewport, snaps to the nearest edge, recalculates after resize/orientation/virtual-keyboard changes, and leaves a clickable handle when peeking off an edge. Docking remains automatic; the visible controls are limited to icon-only Next, Mute/Unmute, and Close actions plus required official-notice actions.

Position is stored locally under an account-specific key, following the same local preference approach used by Portal themes. Session state and saved positions cannot be reused across account identifiers.

The existing SVG is divided into lightweight animation groups. CSS provides idle floating/breathing, blinking, greeting wave, curious head tilt, important-notice attention, dragging, docking, and inactive sleeping states. Hidden tabs pause animations and `prefers-reduced-motion` disables nonessential animation. No audio, JavaScript animation loop, external animation package, or additional artwork is used.

## Weather provider

Weather is fetched by Laravel for the configured Rodriguez (Montalban), Rizal campus coordinates, never from a student's GPS. Successful responses are cached for 45 minutes by default; failed or incomplete responses are omitted. Requests use a short timeout, and browser clients never call the provider directly.

Open-Meteo is the initial adapter and weather is disabled by default pending institutional approval of attribution and terms. Open-Meteo's free API is intended for non-commercial use, requires attribution under CC BY 4.0, is provided without warranty, and has published request limits. Review the current terms before enabling it: <https://open-meteo.com/en/terms>.

Configuration:

```dotenv
CAMPUS_WEATHER_ENABLED=false
CAMPUS_WEATHER_PROVIDER=open_meteo
CAMPUS_WEATHER_BASE_URL=https://api.open-meteo.com/v1/forecast
CAMPUS_WEATHER_LATITUDE=14.7314
CAMPUS_WEATHER_LONGITUDE=121.1447
CAMPUS_WEATHER_LOCATION="Rodriguez, Rizal"
CAMPUS_WEATHER_CACHE_MINUTES=45
CAMPUS_WEATHER_TIMEOUT=3
```

Set `CAMPUS_WEATHER_ENABLED=false` and clear Laravel's configuration cache to disable weather safely. An unknown provider value also disables the adapter. Forecast messages are labeled informational, show their source/time context, and explicitly state that they are not suspension advisories.

## Extending notice sources

Add account-scoped data retrieval and normalization to `PortalNoticeAggregator`. Keep the normalized fields (`id`, `type`, `title`, `message`, `priority`, classification/source, effective/expiry dates, safe link, and acknowledgement state) so the mascot component does not need source-specific branches. Do not add independent browser polling or expose records owned by another account.

## Migration and rollback

The proposed migration `2026_10_22_000001_create_campus_notice_tables.php` adds:

- `campus_notices`
- `campus_notice_audit_events`
- `campus_notice_acknowledgements`

It does not alter or remove historical Event tables. Review and back up the target database before running `php artisan migrate`. No migration was executed during development.

To roll back immediately after applying this migration, first archive/export any newly created notice and audit data, put the application in an appropriate maintenance window, and run a targeted Laravel migration rollback for this batch. The migration's `down()` removes only the three new tables. Do not use a database reset.

## Manual UAT

- Sign in as Student and Guest on web and Android widths; confirm the Dashboard greeting appears after about 750 milliseconds or after the first meaningful interaction.
- Navigate among Dashboard, Enrollment, Grading, Monitoring, Document Requests, and Guest Admission. Confirm each module gets relevant guidance, query changes do not replay it, rapid navigation leaves no stale bubble, and Buddy is not recreated. Confirm it is hidden in Settings and for Admin, Registrar, and Professor.
- Drag with mouse, touch, and stylus-like pointer input; distinguish tap from drag, test both automatic edge docks/peek handles, and rotate/resize/open the Android keyboard. Confirm route navigation preserves the position.
- Mute automatic messages, navigate through several modules, reload, and confirm no automatic bubble appears. Confirm manual mascot interaction and official notices still work. Sign in as another account and confirm the preference does not leak; unmute and confirm contextual greetings resume.
- Confirm routine auto-minimize, hover/focus/hidden-page pausing, manual reopen, icon-only Next/Close/Mute controls, safe route navigation, Escape, focus restoration, and reduced-motion behavior.
- Confirm a form field or modal delays automatic opening and the widget does not obstruct mobile navigation.
- Verify official versus informational labels, source/time context, and weather failure omission.
- Create/edit a draft as Registrar; confirm prohibited school-policy and class-suspension categories are rejected.
- As Admin, create a fully sourced class-suspension draft, publish it, acknowledge it from one Student, and confirm another account remains unacknowledged.
- Test scheduled, expired, audience-specific, recurring Manila-weekday, archived, and empty-result states.
- Confirm Professor never sees Buddy and cannot access notice APIs.
- Regression-check Admission, Enrollment, grading/bulk entry, Academic Monitoring/AI Help, Student/Physical Records, Document Requests/claim emails, and staff platform restrictions.
