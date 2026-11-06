# UI 2.0 Student Profile Visual Refinement

## Scope

This correction refines the existing expandable Student Dashboard profile on the website client only. It does not change the expansion mechanism, Dashboard data contract, CDM Buddy logic, navigation, Android presentation, Electron presentation, Laravel, or the database.

Pre-change copies of the affected files, the tracked working-tree patch, and the full status listing were preserved at `/tmp/cdm-profile-visual-refinement.EOJPn7`.

## Root causes

The earlier profile inherited the generic elevated and soft surface tokens, so its primary surface rendered white or gray in Light mode instead of using CDM Portal's green identity. The profile also forced a full-height card around a fixed 218 px, vertically centered summary. That combination left the avatar and name floating in unused space when the neighboring 2×2 academic metric grid determined the row height.

The expanded view used a one-pixel-gap bordered grid whose cells inherited pale generic surface tokens. It consequently looked like a disconnected gray table. Every configured field rendered even when no authorized value existed, producing `Not available` placeholders and the appearance of filler tiles.

## Corrected minimized composition

The profile remains on the left of the unchanged 2×2 academic summary grid. Its surface is now a layered forest-green treatment based on `#064e3b` and `#042017`, with the existing website emerald and academic-gold tokens providing restrained accents.

The compact content is arranged deliberately:

- an uppercase Student Profile label and the existing top-right diagonal expand control;
- one synchronized account avatar, display name, and Student badge;
- a compact facts region for the current student number, academic level/section, and program;
- only facts present in the authorized Dashboard or authenticated account state.

The Dashboard profile cell uses flex alignment so the green surface balances the height established by the metric grid. Its internal content uses the available height rather than centering a small identity block inside an oversized fixed minimum height. The redundant Academic Overview banner remains absent.

## Corrected expanded composition

The same green profile surface expands to the full command-region width above the existing academic metric cards. The left side retains the compact identity composition, including student number and program/year/section context. The right side presents verified account information as responsive translucent green tiles.

Official name and Program receive full-row width so long values remain readable. Other supplied values use a two-column grid, and a final odd tile spans the row instead of creating an empty eighth tile. Missing values are filtered before rendering; no fabricated profile field or `Not available` tile is introduced. If the account exposes no additional verified values, one explanatory text state replaces the tile grid.

## Responsive, theme, motion, and accessibility handling

- At 820 px and below, the profile and academic metric grid stack in the existing Dashboard flow.
- At 780 px and below, expanded identity and account information become vertically stacked.
- At 520 px and below, compact facts and expanded details use one column, the avatar scales to 68 px, and long values wrap without horizontal overflow.
- The base treatment remains forest green in Light mode. Dark and System-dark modes use an elevated `#075b46` to `#031b14` treatment with stronger shadow and readable white/mint content.
- The expand/collapse button is a 44 px touch target with hover and focus-visible feedback, existing labels, `aria-expanded`, and `aria-controls`.
- The existing 380 ms expansion and FLIP Dashboard movement remain intact. Reduced-motion mode still removes the transitions.
- `data-buddy-obstacle` remains on the profile surface, allowing the existing Buddy positioning system to avoid the card and its top-right control.

## Files changed for this correction

- `CDM_Frontend/src/components/website/StudentDashboardProfile.vue`
- `CDM_Frontend/src/modules/dashboard/websiteDashboard.css`
- `CDM_Frontend/test/websiteDashboardProfileSearch.test.js`
- `docs/UI_2_STUDENT_PROFILE_VISUAL_REFINEMENT.md`
- `docs/UI_2_PROFILE_SEARCH_REFINEMENT.md`

## Validation

- Focused Dashboard profile, avatar, and Step 7 tests: **3 passed, 0 failed**.
- Full frontend suite: **53 passed, 0 failed**.
- Website production build: **passed** with `https://api.example.invalid/api` as an inert build-only `VITE_API_BASE_URL`.
- Android mobile-development build: **passed**.
- Electron desktop-development renderer build: **passed**.
- `git diff --check`: **passed**.

The first website build command used the wrong environment variable name and was rejected by the repository's expected production URL guard before compilation. The corrected build used `VITE_API_BASE_URL` and passed; no `.env` file was changed.

## Manual visual UAT

No browser automation or controlled screenshot session was available in this environment, so pixel-level rendered quality remains a manual check.

1. At 1440 px and 1024 px, verify the compact green profile is left of four metrics in a balanced 2×2 grid.
2. Confirm the compact panel has no large blank center and shows only current student number, program, year, and section values that exist.
3. Expand the profile and verify the same green surface moves above the metric cards without a white flash.
4. Confirm long official names, email addresses, and Program values remain readable and no empty eighth tile appears.
5. Repeat at 768 px, 390 px, and 320 px; verify one-column details, accessible controls, and no horizontal overflow.
6. Repeat in Light, Dark, and System modes; verify white/mint text and the gold/emerald accents retain sufficient contrast.
7. Activate the expand/collapse control with mouse, touch, Enter, and Space, then repeat with reduced motion enabled.
8. Move or dock CDM Buddy near the profile and confirm it does not cover the expansion control or essential details.
9. Confirm all four academic metrics retain their real values and no Academic Overview banner returns.
10. Open the Android and Electron clients and confirm their existing Dashboard presentation is unchanged.
