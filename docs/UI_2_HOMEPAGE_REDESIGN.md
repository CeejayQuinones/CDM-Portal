# CDM Portal UI 2.0 Public Homepage Redesign

## Scope and recovery

This phase redesigns only the unauthenticated website homepage rendered for `VITE_CDM_CLIENT=web`. It does not change authenticated dashboards, the website rail/topbar, CDM Buddy, Android or Electron presentation, Laravel, database configuration, or academic business logic.

Pre-change copies of the affected files, tracked working-tree patch, status listing, and branding-asset checksums are preserved at `/tmp/cdm-homepage-redesign.akfkwf`.

## Component architecture

- `LoginView.vue` remains the owner of authentication state, credential submission, Google sign-in, validation, and Guest registration.
- `WebsitePublicHome.vue` owns the website-only institutional presentation, anchor navigation, responsive public menu, section reveals, and public calls to action.
- `publicHomepage.js` is the maintainable static source for the user-supplied institution profile, history, academic institutes, mission/vision, supported portal services, and role entry descriptions.
- `website-public-home.css` contains the isolated homepage design system, hero treatment, responsive layouts, Light/Dark/System colors, focus states, and reduced-motion rules.
- The existing non-web `public-page` markup and `public-home.css` remain the Android/Electron presentation.

`WebsitePublicHome.vue` is loaded through a compile-time web-only lazy import. Final Android and Electron builds do not emit the homepage component, its CSS, or the building photograph.

## Building photograph and branding

The hero imports the existing photograph directly from:

`CDM_Frontend/src/assets/styles/images/CDM-bdng.png`

The original file remains unchanged. It is a 409×230 RGBA PNG. The hero uses `background-size: cover`, responsive positioning, and the requested forest-green overlay:

`linear-gradient(90deg, rgba(4,32,23,.94), rgba(6,78,59,.72), rgba(4,32,23,.25))`

No blur, AI replacement, stretching, or destructive image processing is applied. The building remains steady while content overlays and section elements use restrained motion.

The existing platform-aware `portalSealUrl` remains the only logo source. The approved website `cdm-seal.png` is reused without redrawing, filtering, or duplicating the binary asset. Repeated decorative seal instances have empty alternative text; the About profile uses `Colegio de Montalban seal`.

## Institutional information sources

Institution profile, history milestones, academic institutes/programs, mission, and vision come only from the content supplied for this task. The page explicitly presents the program list as informational and directs visitors to the authenticated Admission workflow for current availability.

No database query, public statistics, testimonials, awards, tuition details, application-slot claims, announcements, events, or private records were added. The homepage component performs no API request.

## Navigation and calls to action

The public header includes Home, About, Academic Programs, Portal Services, Log In, and Admission / Get Started. Section destinations use smooth in-page navigation and close the mobile menu after selection.

- **Log In** moves focus context to the existing credential/Google login panel.
- **Admission / Get Started** and **Applicants** open the existing Guest registration wizard. Applicants then sign in and use the already authorized Admission module.
- **Students** and **Professors** move to the existing login panel.
- Service content explains that account authorization is required and exposes no private data.

The existing password login, Google sign-in, validation, registration success handling, legal links, and role-based post-login routing remain unchanged.

## Visual design and motion

The page follows an editorial institutional progression:

1. Sticky public navigation.
2. Building-photo hero and account login.
3. Two-column institution profile.
4. Four-point history timeline.
5. Three academic institute cards.
6. Mission and vision editorial panel.
7. Supported service bento grid.
8. Applicant, Student, and Professor entry points.
9. Institutional footer and legal navigation.

The design uses forest/deep green, emerald/mint, academic gold, and the light canvas through semantic homepage variables with existing `--website-*` fallbacks. Hover movement is limited to small card/button elevation. Intersection Observer reveals use opacity and a 22 px vertical offset, reveal only once, and fall back to immediately visible content when the API is unavailable.

`prefers-reduced-motion` disables reveal transforms and effectively removes transitions. The photograph itself is never animated.

## Responsive and theme decisions

- Desktop uses a two-column hero with the building visible behind the editorial headline and login card.
- The public menu changes to an accessible 44 px menu control below 900 px with `aria-expanded`, `aria-controls`, and Escape handling.
- About and mission/vision move to one column at tablet widths.
- The timeline changes from horizontal to a vertical sequence on narrow screens.
- Program, service, and role layouts progressively collapse without horizontal page overflow.
- Mobile hero positioning keeps the central building facade and school signage as practical within the source photograph.
- Light uses the supplied light canvas and white elevated surfaces. Dark and System-dark use elevated green-black surfaces while retaining the consistent dark-green image overlay.

CSS breakpoints cover the requested 1440, 1024, 768, 390, and 320 px review widths through rules at 1080, 900, 720, 430, and 340 px.

## Files changed

Added:

- `CDM_Frontend/src/components/public/WebsitePublicHome.vue`
- `CDM_Frontend/src/assets/styles/website-public-home.css`
- `CDM_Frontend/src/config/publicHomepage.js`
- `CDM_Frontend/test/websitePublicHomepage.test.js`
- `docs/UI_2_HOMEPAGE_REDESIGN.md`

Modified:

- `CDM_Frontend/src/views/LoginView.vue`

Used without modification:

- `CDM_Frontend/src/assets/styles/images/CDM-bdng.png`
- `CDM_Frontend/src/assets/styles/images/cdm-seal.png`
- `CDM_Frontend/src/assets/styles/images/cdm_logo.png`
- `CDM_Frontend/src/assets/styles/public-home.css`

## Validation results

- Focused homepage, login, branding, and platform-access tests: **4 passed, 0 failed**.
- Full frontend suite: **54 passed, 0 failed**.
- Website production build: **passed** with an inert command-only `VITE_API_BASE_URL=https://api.example.invalid/api`.
- Android mobile-development build: **passed**; no website homepage chunk, CSS, or building asset emitted.
- Electron desktop-development renderer build: **passed**; no website homepage chunk, CSS, or building asset emitted.
- `git diff --check`: **passed**.

No `.env`, backend, database, deployment, authenticated navigation, or academic workflow file was changed for this phase.

## Known limitations

- The supplied building photograph is only 409×230. It is preserved exactly, but large high-density displays may reveal its source-resolution limitation.
- No controlled browser or screenshot automation was available, so final visual judgment, exact mobile crop approval, and screenshot comparison remain manual UAT.
- Mission, vision, institutional history, and program listings are presented as task-supplied content rather than independently authenticated live institutional data.

## Manual visual UAT checklist

1. Open the logged-out website at 1440 px and confirm the Ynares School Building, its name, and the Colegio de Montalban facade remain recognizable beneath the overlay.
2. Verify Log In, Admission / Get Started, Applicants, Students, and Professors lead to the described existing login/registration flows.
3. Check all public navigation anchors, sticky-header offsets, mobile menu opening/closing, Escape handling, and keyboard focus indicators.
4. Review the supplied About facts, all four history entries, eight listed degree programs, mission, and vision word-for-word.
5. Confirm program cards do not suggest that applications or slots are currently open.
6. Confirm the seven service descriptions match real portal capabilities and expose no account information.
7. Review 1440, 1024, 768, 390, and 320 px widths for photo cropping, text contrast, CTA wrapping, timeline readability, and horizontal overflow.
8. Repeat in Light, Dark, and System appearances.
9. Enable reduced motion and confirm sections display without animated translation.
10. Test password login, Google login, validation errors, Guest registration, Terms, and Privacy links.
11. Open Android and Electron and confirm their previous login presentation and branding remain unchanged.
