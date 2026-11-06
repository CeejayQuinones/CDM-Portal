# UI 2.0 Dashboard Profile and Morphing Search

## Scope and architecture

This refinement is isolated to the website UI 2.0 shell. `DashboardLayout.vue` still selects `WebsiteAppShell.vue` only when `VITE_CDM_CLIENT=web`; Android and Electron continue to render the legacy shell.

The implementation uses:

- `StudentDashboardProfile.vue` for the collapsed and expanded in-page Student profile.
- The existing account-scoped `studentProfile` Pinia store and `WebsiteUserAvatar.vue` for synchronized images and initials fallback.
- Authenticated `/me` state for email and contact information.
- The existing Student Dashboard response for official name, student number, program, year, and section.
- `studentDashboardProfile.js` for the small, explicit authorized-data mapping and the shell-to-dashboard expansion event.
- `websiteSearch.js` for destination filtering and keyboard-selection helpers.
- The existing role-filtered website navigation model as the sole source of search destinations.

No backend endpoint, database schema, business rule, or academic record state was changed.

## Student Dashboard profile

The compact Dashboard command region places the Student Profile card on the left and the four existing academic summary cards in a 2×2 grid on the right. The former Academic Overview hero and its duplicate identity strip were removed. The compact card contains the synchronized profile image or initials, preferred/official display name, Student role, and available student-number/program/year/section context. Its refined forest-green presentation is documented in `UI_2_STUDENT_PROFILE_VISUAL_REFINEMENT.md`.

A dedicated diagonal maximize button in the card's top-right corner expands the profile. The card body itself is not an expansion trigger. When expanded, the profile occupies the full command-region width and the four academic summary cards move into a responsive row beneath it. The control changes to a diagonal minimize icon and exposes descriptive `aria-expanded`, `aria-controls`, label, title, and focus states.

The expanded normal-flow panel shows only currently authorized fields: student number, official name, email, contact number, program, year level, and section. Missing values are omitted instead of producing placeholder tiles; no modal, dropdown, drawer, overlay, or fabricated detail is used.

The profile reveal and dashboard arrangement use 380 ms layout-aware transitions. A FLIP-style Web Animations pass moves the profile and summary regions using translation and clipping without scaling text or relying on timeouts. Reduced-motion mode bypasses these animations.

## Profile and Settings actions

The website rail no longer contains an account avatar or empty placeholder. Its 72 px geometry, Settings item, connected curved selection, and jelly animation remain unchanged.

The topbar account control remains the account identity entry point:

- **Profile** is Student-only. It uses the authorized `student-dashboard` route, expands the in-page profile, and restores focus to its toggle.
- **Settings** remains a separate Student Settings route.
- **Professor Profile and settings** retains the existing Professor Settings behavior.
- Guests receive no unsupported Settings or Student Profile action.
- **Sign out** remains available for every authenticated role.

## Morphing search

The existing topbar destination search now morphs from its 42 px icon surface into a left-growing search field while its right edge remains in the topbar action group. The topbar height, rail width, route container, and page layout are not animated.

Search behavior includes:

- Live matching against authorized destination label, module label, and existing description.
- Up/Down wraparound selection.
- Enter navigation through Vue Router.
- Escape close with focus restored to the search trigger.
- Pointer-outside dismissal without stealing focus.
- Clear/close control and explicit no-results feedback.
- Automatic mutual exclusion with the account menu.
- Close after destination selection.

The width morph uses 290 ms `cubic-bezier(0.22, 1, 0.36, 1)`. Input content fades over 160 ms and results use a restrained opacity/vertical reveal. All search transitions are removed under `prefers-reduced-motion`.

## Responsive and theme handling

The profile uses the established forest-green identity in both compact and expanded states, with existing website emerald/gold tokens and explicit dark/System-dark forest treatments. Other new surfaces continue to use the existing `--website-*` semantic tokens.

At narrow widths, the workspace label and notification shortcut temporarily yield space while search is open. The mobile menu and account control remain accessible. Search width is bounded by `calc(100vw - 126px)` at the narrowest breakpoint and does not change page or route-container width.

Below 820 px the compact profile and academic summary cards stack naturally. The expanded identity/details composition also becomes one column, and its information grid becomes a single column on small phones.

## Files added

- `CDM_Frontend/src/components/website/StudentDashboardProfile.vue`
- `CDM_Frontend/src/config/studentDashboardProfile.js`
- `CDM_Frontend/src/config/websiteSearch.js`
- `CDM_Frontend/test/websiteDashboardProfileSearch.test.js`
- `docs/UI_2_PROFILE_SEARCH_REFINEMENT.md`

## Files modified

- `CDM_Frontend/src/components/website/WebsiteNavigationRail.vue`
- `CDM_Frontend/src/components/website/WebsiteTopbar.vue`
- `CDM_Frontend/src/layouts/WebsiteAppShell.vue`
- `CDM_Frontend/src/modules/dashboard/StudentWebsiteDashboard.vue`
- `CDM_Frontend/test/websiteProfileAvatar.test.js`
- `CDM_Frontend/test/websiteStepSeven.test.js`
- `CDM_Frontend/test/websiteUiTwoStepFour.test.js`

## Regression and build results

- Full frontend test suite: **53 passed, 0 failed**.
- Website production build: **passed** with an inert build-only HTTPS API URL.
- Android mobile-development build: **passed**.
- Electron desktop-development renderer build: **passed**.
- `git diff --check`: **passed**.

The test suite covers compact left/profile-right-summary composition, full-width expansion with summaries beneath, diagonal maximize/minimize controls, FLIP movement without timers, Profile-action expansion and focus, authorized data mapping, synchronized avatar/fallback behavior, account isolation, rail avatar removal, Profile/Settings separation, route-authorized search filtering, keyboard selection, Escape restoration, responsive stacking, reduced-motion rules, and platform isolation.

## Remaining limitations

- No controlled browser/screenshot session was available, so final pixel-level comparison against the hand-drawn wireframe and approved mockup remains manual UAT.
- The expanded profile intentionally cannot show data not present in the existing authenticated or Student Dashboard responses.
- Professor profile upload remains unsupported; the existing read-only photo field or initials fallback is preserved.

## Manual visual UAT checklist

1. Sign in as a Student with a saved profile image and confirm the topbar and collapsed Dashboard profile show the same image.
2. Confirm the compact profile is left of a 2×2 academic summary grid at desktop widths.
3. Activate the diagonal maximize/minimize control by mouse, touch, Enter, and Space; confirm the card body does not toggle unexpectedly.
4. Confirm the expanded profile is full-width with the academic summary cards beneath it, and details match the signed-in account.
5. Open the account menu from a non-Dashboard route, choose Profile, and confirm navigation plus in-page expansion and focus.
6. Confirm Settings remains a separate action and Sign out still works.
7. Upload, replace, and remove the Settings photo; confirm the topbar and Dashboard profile update without reload.
8. Switch accounts and confirm no prior photo or personal information appears.
9. Open search and verify it grows left without moving the rail, topbar height, or Dashboard.
10. Test live filtering, Up/Down wrapping, Enter navigation, Escape restoration, clear, outside click, and no-results text.
11. Repeat at 1440, 1024, 768, 390, and 320 px in Light, Dark, and System modes.
12. Enable reduced motion and confirm the profile and search changes occur without animation.
13. Confirm Android and Electron retain their existing sidebar and dashboard presentation.
