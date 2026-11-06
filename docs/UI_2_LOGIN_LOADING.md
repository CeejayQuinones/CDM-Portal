# CDM Portal UI 2.0 — Branded Login Loading

## Scope

This change adds a website-only branded pending state for real username/password and Google authentication. It does not change Laravel authentication, account authorization, route policy, Android presentation, Electron presentation, database configuration, or environment files.

Recovery checkpoint before this change: `/tmp/cdm-login-loading.ZKoMTO`.

## Logo asset used

- Source: `CDM_Frontend/src/assets/styles/images/cdm-seal.png`
- Format: genuine PNG (not renamed or wrapped as SVG)
- Intrinsic dimensions: 1254 × 1254 pixels, RGBA
- SHA-256 before and after implementation: `117da29e58dc7cdcb7054072b4b09f8781ea3e99d3ccec235fa6e1f4e83d4097`
- Rendering path: `portalSeal.js` → `CDMSeal.vue` → `WebsiteLoginLoading.vue`

No SVG seal exists in the repository. The approved high-resolution PNG is therefore used unchanged. Its artwork, transparency, proportions, lettering, symbols, and colors were not processed or modified.

## Component structure

`WebsiteLoginLoading.vue` provides a fixed, layout-stable loading surface above the public website login:

- Deep forest background (`#042017`).
- Decorative shared `CDMSeal` instance with its native 1:1 aspect ratio and `object-fit: contain`.
- A separate radial-gradient element behind the seal, so the seal artwork remains stationary and unfiltered.
- “Signing you in” status text and “Connecting securely to CDM Portal…” supporting copy.
- A small indeterminate horizontal progress line with no percentage or claimed completion value.
- Safe-area padding, fluid seal sizing, and a 480px mobile refinement.
- `role="status"`, polite/atomic live-region semantics, and one concise accessible label.

The seal has no added border, gold decoration, hard circular outline, spinning ring, rotation, bounce, scale pulse, or movement. Its only entrance treatment is a short opacity fade.

## Emerald glow and motion

The independent glow layer uses:

- Primary color: `rgba(16, 185, 129, 0.22)`.
- Secondary color: `rgba(52, 211, 153, 0.10)`.
- Size: 132% of the rendered seal (32% larger).
- Blur: 32px.
- Breathing duration: 2.8 seconds, `ease-in-out`.
- Opacity range: 0.40–0.75.
- Overlay and seal entrance/fade timing: 220ms.

With `prefers-reduced-motion: reduce`, the entrance, glow breathing, progress movement, and transition are disabled. A stationary glow at 0.56 opacity and the status content remain visible.

## Authentication integration

`LoginView.vue` passes its existing `isLoading` state to the website homepage. The homepage owns the loading component because it is already loaded only behind the compile-time `VITE_CDM_CLIENT === 'web'` branch.

For credential login:

1. Existing field validation completes first.
2. `runLoginRequest` sets pending state immediately before calling the existing auth-store login method.
3. A second submission exits while pending and both login buttons are disabled.
4. Pending state remains active while the auth request and awaited role/query-based `router.replace` complete.
5. Authentication or network errors run the existing accessible error handler and clear pending state in `finally`.

Google code exchange uses the same visible pending state once the backend exchange begins. Its existing error feedback and role redirect are preserved.

No timer, artificial minimum duration, fake success state, credential logging, or backend behavior was added. A view-active guard prevents late asynchronous callbacks from updating a destroyed login view. Unmount cleanup removes the Google message listener and closes an outstanding popup.

## Success and failure behavior

- Success: the overlay remains present through the awaited authorized redirect and leaves with the login view as the destination becomes active.
- Invalid credentials/backend rejection: the overlay fades out and the existing backend message/field feedback is shown.
- Network or timeout failure: the overlay fades out and the existing actionable retry message remains available in the login card.
- Validation rejection: the overlay never starts because no authentication request was accepted.

## Platform isolation

`WebsiteLoginLoading.vue` is imported by `WebsitePublicHome.vue`, and `WebsitePublicHome.vue` is dynamically imported only when the compile-time client is `web`. The legacy `v-else` login used by Android and Electron does not reference the loader.

Build artifact checks found the loader copy and glow styles in the website `WebsitePublicHome` chunk. The same strings/component were absent from both the mobile-development and desktop-development renderer outputs.

## Files changed for this enhancement

- Added `CDM_Frontend/src/components/website/WebsiteLoginLoading.vue`.
- Added `CDM_Frontend/test/websiteLoginLoading.test.js`.
- Modified `CDM_Frontend/src/components/public/WebsitePublicHome.vue`.
- Modified `CDM_Frontend/src/views/LoginView.vue`.
- Added `docs/UI_2_LOGIN_LOADING.md`.

## Validation results

- Focused login/homepage/Google tests: pass.
- Full frontend suite: pass, 55/55 test files.
- Website production build: pass using build-time-only `VITE_API_BASE_URL=https://build.invalid/api`; no `.env` file was changed.
- Android mobile-development build: pass.
- Electron desktop-development renderer build: pass.
- Website-only bundle isolation checks: pass.
- `git diff --check`: pass.
- Approved seal SHA-256 preservation: pass.

No database, migration, seeder, Laravel command, deployment, commit, push, merge, reset, clean, or stash operation was performed.

## Manual visual UAT

Browser screenshot automation was unavailable, so complete these visual checks locally:

1. At desktop widths, submit valid Student, Guest, and Professor credentials and confirm the overlay begins only after valid form submission and remains until the correct dashboard appears.
2. Submit invalid credentials and simulate a stopped API server; confirm the overlay disappears and the login card exposes the existing error and retry path.
3. Double-click Sign in and confirm only one request is accepted.
4. Complete Google sign-in and verify the overlay appears during the backend code exchange, not while idle on the page.
5. Check Light, Dark, and System appearances; the branded forest surface should remain identical and readable.
6. Check 1440px, 1024px, 768px, 390px, and 320px widths for centered seal, unclipped glow, safe-area spacing, and no horizontal overflow.
7. Enable reduced motion and verify the seal and glow remain stationary with no entrance/progress animation.
8. Inspect at high zoom and confirm the approved seal is not stretched, cropped, recolored, filtered, or surrounded by a visible ring/border.
9. Launch Android and Electron and confirm their existing login presentation is unchanged.
