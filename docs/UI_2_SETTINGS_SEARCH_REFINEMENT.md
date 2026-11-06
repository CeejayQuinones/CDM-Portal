# CDM Portal UI 2.0 — Search Focus and Settings Refinement

## Scope and recovery

This task changes only the website presentation for the topbar destination search and Student/Professor Settings. It does not change backend APIs, database state, authentication policy, academic workflows, primary navigation geometry, the secondary panel, route-transition behavior, CDM Buddy, Android presentation, or Electron presentation.

The complete pre-change dirty worktree is recoverable from `/tmp/cdm-settings-search.kvgw5d`:

- `tracked-worktree.patch` SHA-256: `2173e2224a4e796751d48cbf25b5ee7ecdc0c9f8c3c2c23936d0f04638f94826`
- `untracked-files.tar.gz` SHA-256: `e53f0615f044776c29b65c471173a9252c106963a702a3562bac0b9b122318e9`
- The checkpoint also contains the starting Git status, stash list, untracked-file inventory, and copies of affected pre-change sources/tests.

## Search double-border root cause and fix

`WebsiteTopbar.vue` correctly removed the internal search input border and outline in its local rule. A later shell-wide `.website-ui-2-shell :focus-visible` rule nevertheless supplied a three-pixel outline to every focused control. The global rule won in the rendered cascade, so the input drew a second inner rectangle inside the already bordered `.search-surface`.

The fix is deliberately local:

- The internal search input now resets native search appearance, border, radius, outline, background, and box shadow for its default, `:focus`, and `:focus-visible` states.
- The selector includes the outer search surface, giving it enough specificity to override shell-wide input/focus styling without using `!important`.
- The input retains a clear emerald caret and semantic placeholder color.
- `.search-surface:focus-within` owns the only field-level focus treatment: an emerald border and soft three-pixel outer halo.
- Search result links and the clear/close button keep their own keyboard focus indicators.

No search state, API scope, destination authorization, result filtering, morph timing, arrow-key selection, Enter activation, Escape handling, outside-click handling, focus restoration, or reduced-motion behavior changed.

## Settings architecture

The previous presentation used a fixed 220px category column next to a relatively narrow content card. The new website workspace keeps the same `SettingsView` state and API methods but changes the information architecture when `clientPlatform === 'web'`:

1. A compact shared module heading introduces the workspace.
2. A forest/emerald identity banner shows one synchronized account photo or initials fallback, display name, and role.
3. Profile-photo guidance and the existing Change Photo/Remove actions appear in that banner only while Profile is selected, avoiding adjacent duplicate avatars.
4. A category grid replaces the tall website sidebar.
5. One spacious content workspace provides category context, fields, feedback, and actions.

The existing non-web page retains the legacy header, sidebar/drawer, and responsive rules. All new visual selectors are rooted in `.website-settings-workspace`, and the legacy mobile drawer selectors explicitly exclude that class.

## Category navigation

Student Settings retains every supported category:

- Profile
- Account
- Student Status
- Record Requirements
- Document Status
- Contact Information
- Notifications
- Academic Preferences
- Appearance
- Security

Professor Settings remains self-scoped and exposes only its supported Profile, Account, Appearance, and Security categories. It does not expose Student status, records, documents, contacts, notifications, or academic-preference data.

Each category control is a native button with a concise icon, visible category name, grouping label, and `aria-current` on the active destination. The category heading inside the content area explains the selected workspace.

Navigation adapts as follows:

- Wide desktop: five-column compact category grid.
- Medium desktop/tablet: three-column grid.
- 768px and narrower: independently scrolling horizontal category row with scroll snapping.
- 340px refinement: narrower cards and safe page padding without page-level horizontal scrolling.

## Profile and form layout

- Preferred display name and Bio keep their existing state, payload, length limits, and API endpoint. They now have explicit optional labels, readable placeholders, and more generous vertical spacing.
- The approved account-scoped avatar store remains the source of the website photo. Existing JPEG/PNG/WebP validation, 2MB optimization limit, multipart upload, cache revision, and removal logic are unchanged.
- Official identity values are presented as responsive read-only key/value cards. Empty values are removed from the website grid rather than producing empty cards; long program and department values wrap by words instead of breaking every character.
- Professor contact and official information remains read-only because no supported professor self-service profile endpoint exists.
- Contact fields use correct email, telephone, and address autocomplete/input modes.
- Notifications and academic preferences retain their existing booleans and save endpoint, with explanatory toggle-card copy.
- Security retains `/change-password` and existing validation/error handling, adding current/new password autocomplete semantics and explanatory guidance.
- Loading, error, success, dirty, reset, saving, and disabled states remain connected to the existing state variables.

## Appearance and themes

The existing Light, Dark, and System radio-card choices remain keyboard accessible and continue to call the current preview/persistence composables. Account-scoped storage keys and server-backed Student preference behavior were not duplicated or replaced.

New surfaces use UI 2.0 semantic tokens for background, text, border, input, hover, status, focus, and shadow colors. The branded banner intentionally remains a readable forest-green surface in all themes. The restrained gold accent appears only on the selected category edge and security guidance. The local Settings reduced-motion rule disables transitions, while the existing website-shell rule reduces feedback and loading animations to a single near-instant frame.

## Files changed

- `CDM_Frontend/src/components/website/WebsiteTopbar.vue`
- `CDM_Frontend/src/views/SettingsView.vue`
- `CDM_Frontend/test/studentSettings.test.js`
- `CDM_Frontend/test/websiteSettingsSearchRefinement.test.js` (new)
- `docs/UI_2_SETTINGS_SEARCH_REFINEMENT.md` (new)

## Automated validation

- Focused search, Settings, profile synchronization, record requirements, platform, and theme regressions: pass.
- Full frontend test suite: pass, 56/56 test files.
- Website production build: pass using build-time-only `VITE_API_BASE_URL=https://build.invalid/api`; no environment file changed.
- Android mobile-development build: pass.
- Electron desktop-development renderer build: pass.
- Android executable output: web Settings banner markup and website topbar search implementation absent.
- Electron executable output: web Settings banner markup and website topbar search implementation absent.
- `git diff --check`: pass.

No Laravel command, migration, seeder, database operation, commit, push, merge, reset, clean, stash operation, or deployment was performed.

## Manual screenshot and UAT checklist

Automated browser screenshots were unavailable in this environment. Capture and review the following locally before approval:

### Search

1. At 1440px, 1024px, 768px, 390px, and 320px, open search with the mouse and keyboard.
2. Confirm the expanded search is one seamless rounded surface with no inner green rectangle or native search inset.
3. Confirm the outer emerald focus halo is visible against Light, Dark, and System themes.
4. Confirm the caret and placeholder remain readable.
5. Exercise Arrow Up/Down, Enter, Escape, outside click, clear/close, and restored trigger focus.
6. Confirm the search continues morphing right-to-left without moving the navigation rail or topbar.

### Student Settings

1. Capture Profile, Record Requirements, Notifications, Appearance, and Security in Light and Dark modes at 1440px.
2. Confirm the banner contains one avatar only, and Change Photo/Remove remain reachable on Profile.
3. Upload valid and invalid photo types, replace a photo, remove it, and verify the topbar/sidebar image stays synchronized.
4. Edit preferred display name and Bio; verify dirty, reset, saving, success, and error states.
5. Confirm official values are visibly read-only, empty cards are absent, and long program names remain readable.
6. Review all ten categories and confirm their existing actions and data remain available.
7. Select Light, Dark, and System, reload, and sign into another test account to verify preferences persist without leaking between accounts.
8. Change a password using validation-error and success cases.

### Professor Settings

1. Confirm only Profile, Account, Appearance, and Security appear.
2. Confirm faculty identity/contact information is read-only and no unsupported edit/upload endpoint is presented.
3. Confirm appearance persistence is account-scoped and password change retains existing behavior.

### Responsive and platform isolation

1. At 1024px, verify the category grid uses available width without crowding content.
2. At 768px, 390px, and 320px, horizontally scroll the category row using touch, mouse, and keyboard-accessible buttons.
3. Confirm fields and information cards stack, banner controls do not clip, sticky save actions remain reachable, and the page has no horizontal overflow.
4. Enable reduced motion and confirm large movement/spinning feedback is suppressed.
5. Launch Android and Electron and compare their Settings pages with the pre-change presentation; the website banner/grid must not appear.
6. Confirm the 72px rail, secondary panel, topbar dimensions, jelly selection, dashboard profile, CDM Buddy, and opacity-only route transitions are unchanged.
