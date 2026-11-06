# CDM Portal UI 2.0 — Step 6.1: Curved rail seam and motion refinement

## Scope

This is a local website-only refinement of the existing Step 6 rail on `dev` at `bf7a689`. Earlier uncommitted work and recovery stashes were preserved. No backend, database, environment, Android/Electron presentation, or Git history changes were made.

## Seam analysis and correction

The manual report identified visible seams. Source inspection found three plausible contributors, but there is no screenshot-based measurement of each contributor here:

1. The rail had an outward right-side box shadow that painted across its shared boundary with the panel/workspace. That shadow was removed.
2. The selected surface and curves ended exactly at the 72px edge. Fractional transforms, zoom, and antialiasing could expose a hairline. The main surface now overlaps the adjacent surface by 2px. Each curve has a 2px fully colored terminal strip at that edge and overlaps the main surface by 1px vertically. The 20px concave shape remains intact.
3. The secondary panel translated 18px horizontally during entry/exit, briefly exposing the workspace behind a selection colored for the panel. The panel now appears at its fixed x=72px boundary without a horizontal transition. Its exact `--website-card` background matches the selected shape while open; the shape uses `--website-canvas` when closed.

No workspace dimensions, margins, rail width, page transition, or broad overlay were changed. The 72px rail remains forest green, and the existing emerald/gold theme tokens remain in use.

## Jelly movement

The rail still has only one active visual element and its two pseudo-element curves. The Web Animations API animates that element for 380ms with `cubic-bezier(.22,1,.36,1)`: a small directional vertical stretch, a glide toward the authorized route, a 4% arrival compression, then an exact normal-height settlement at the destination. Stretch is capped at 12%; there is no final-position overshoot or spring loop. Upward travel shifts the stretched top edge toward the destination, while downward travel stretches toward the lower destination.

On a rapid subsequent route change, the code measures the element's current rendered position and scale, cancels the old animation, then starts the new one from that visual state. A request sequence ignores stale Vue measurements. Initial mount/reload snaps to the active route rather than animating from the top. If the Web Animations API is unavailable, a 380ms transform-only transition is used. If reduced motion is requested, movement, stretch, and compression are skipped and the selection updates immediately. A media-query listener also cancels in-flight motion when that preference changes.

The active authorized route remains the final-position source of truth. The panel and workspace do not animate with the indicator, and Buddy receives no new layout events.

## Files changed

- `CDM_Frontend/src/components/website/WebsiteNavigationRail.vue` — seam overlap, no outward shadow, animation lifecycle and reduced-motion handling.
- `CDM_Frontend/src/components/website/WebsiteNavigationPanel.vue` — fixed-edge panel appearance.
- `CDM_Frontend/src/components/website/railSelectionMotion.js` — pure directional keyframe generator and duration.
- `CDM_Frontend/test/websiteConnectedRail.test.js` — geometry, direction, interruption, theme, platform, and reduced-motion regression coverage.
- `docs/UI_2_STEP_6_1.md` — this report.

## Validation

- Focused connected-rail and navigation-stability tests: **passed**.
- Full frontend suite: **49/49 test files passed** after the final motion adjustment. The existing non-fatal `import.meta`/CommonJS warning remains.
- Website production build: **passed** with an inert HTTPS API placeholder.
- Android mobile-development build: **passed**.
- Electron renderer build: **passed** with an inert HTTPS API placeholder. Electron packaging was not requested.
- `git diff --check`: **passed**. Because the Step 6 website files are untracked in the preexisting dirty tree, they were also checked directly for trailing whitespace.

Build checks do not contact the portal backend. Production build URL guards are satisfied with inert HTTPS placeholders only for local compilation. No browser executable was found, so seam-free rendering is **not** claimed without manual screenshots.

## Manual visual UAT still required

1. Capture Dashboard → Enrollment → Grading → Monitoring → Documents and reverse navigation at 90%, 100%, 110%, and 125% zoom. Check the right boundary and both concave corners frame by frame in Light and Dark.
2. Repeat with the panel open and closed and at narrow desktop width; verify its edge remains at the rail and no tinted hairline appears. Check both System-mode OS appearances.
3. Rapidly switch modules, use browser back/forward, and reload a nested route. Confirm one shape follows the actual route, never tears or settles at a stale item, and the content keeps its 150ms opacity-only transition.
4. Enable reduced motion, navigate up and down, and confirm an immediate but visible selection. Check keyboard focus, labels/tooltips, and Settings.
5. At mobile-browser widths, confirm the desktop cutout remains hidden and the drawer still works. Check Buddy position and absence of horizontal overflow, Enrollment wiggle, or page-scroll jumps.
6. Smoke-test installed Android and Electron navigation. Their presentation is unchanged by source routing, but renderer builds alone cannot prove device visuals.

Visual acceptance remains pending that manual review.
