# CDM Portal UI 2.0 — Step 6: Connected curved sidebar selection

## Scope and baseline

This focused change was made locally on `dev` at `bf7a689` without committing, deploying, altering stashes, or touching backend/database code. Existing dirty work from earlier UI and CDM Buddy steps remains in place. The supplied attachment contained the Step 6 description but no viewable reference images; exact visual matching still needs manual review.

## Geometry and animation

The former active rail button had its own rounded background, outline, and gold stripe. The website rail now renders one noninteractive `.rail-selection` layer behind all module icons. It spans from the button's left edge to the 72px rail's right boundary. The layer has left-hand rounding and a flush right edge. Two pseudo-elements extend 20px above and below it; radial gradients create paired concave transitions from the forest rail into the selected surface. The icon and tooltip remain above the layer.

The active button is measured relative to the fixed rail after Vue updates. On route, account-module, and viewport changes, only the selection layer's vertical translation changes. CSS moves it over 300ms using `cubic-bezier(.22,1,.36,1)`; rapid route changes retarget the same element. The sidebar, workspace width, and Buddy position are not animated or recalculated by this feature. The existing route view remains a 150ms opacity-only transition. Reduced-motion users get an immediate selection change. The desktop layer is hidden with the rail below 901px; the mobile drawer retains its Step 5 behavior.

With the secondary panel closed, the layer uses `--website-canvas`, matching the adjacent workspace. With the panel open, both the layer and panel use `--website-card`. These semantic variables respond to Light, Dark, and System mode; the rail keeps the existing `--website-forest` background and the active icon uses `--website-deep-green`. No reference-site styling or branding was copied.

Active state comes from the authorized role menu and actual route, including nested destinations. Unmatched routes no longer fall back to Dashboard highlighting; a stale secondary panel closes on an unmatched route. Browser back/forward and reload use the same reactive route source. Settings receives `aria-current="page"` like other active rail entries. Focus outlines, labels, and tooltips remain available.

## Files changed for Step 6

- `CDM_Frontend/src/components/website/WebsiteNavigationRail.vue` — single measured selection layer, curves, motion, focus and theme styling.
- `CDM_Frontend/src/components/website/WebsiteNavigationPanel.vue` — exact shared semantic background token.
- `CDM_Frontend/src/layouts/WebsiteAppShell.vue` — no false Dashboard active state or stale panel on unmatched routes.
- `CDM_Frontend/test/websiteNavigationStability.test.js` — updated unmatched-route panel assertion.
- `CDM_Frontend/test/websiteConnectedRail.test.js` — route/history, geometry, theme, reduced-motion, mobile and platform-isolation checks.
- `docs/UI_2_STEP_6.md` — this report.

## Validation

- Full frontend suite: **49/49 test files passed**. An existing non-fatal `import.meta`/CommonJS warning remains.
- Website production build: **passed** with an inert HTTPS API placeholder.
- Android mobile-development build: **passed**.
- Electron renderer build: **passed** with an inert HTTPS API placeholder; Electron packaging is outside this request.
- `git diff --check`: **passed**. The Step 6 files are untracked within the existing dirty tree, so they were also checked for trailing whitespace directly.

Build-time validation uses inert HTTPS API placeholders where the production URL guard requires them. No build contacted a portal API or database. Source-level tests and successful builds do not establish pixel-perfect visual matching.

## Manual visual UAT

1. At desktop widths 1440 and 1024, check the rail at Dashboard, Enrollment, Grading, Monitoring, and Document Requests. Confirm the selected shape joins the adjacent surface with no right seam and two clean concave corners.
2. Repeat with the secondary panel open and closed, including nested Enrollment routes, browser back/forward, direct reload, rapid repeated clicks, and account changes. Confirm the icon and shape follow the actual active authorized route; unmatched/unauthorized routes must not show a false selection.
3. In Light, Dark, and System (both OS appearances), inspect the exact surface match and selected icon contrast. Check no single-frame color seam while the panel opens/closes.
4. Keyboard-tab through the rail; check focus visibility, tooltip/label availability, Enter/Space activation, and Settings. Enable reduced motion and confirm the selection jumps immediately.
5. At 768, 390, and 320 pixels, confirm the mobile drawer remains usable and no desktop cutout appears. Inspect Buddy docking and topbar/route stability after repeated navigation.
6. Smoke-test installed Android and Electron navigation. Their presentation should remain unchanged; renderer/build checks alone do not prove that visually.

No browser screenshots were available in this environment. Final visual acceptance should follow this UAT before further design changes.
