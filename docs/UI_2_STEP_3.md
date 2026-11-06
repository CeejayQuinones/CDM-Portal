# CDM Portal UI 2.0 — Step 3

## Scope and safety

Step 3 adds Professor Settings to the website and completes the shared website Light, Dark, and System appearance layer. It does not add backend account-management APIs, alter database schema or data, restore Event Management, deploy, or change the approved Android/Electron presentation.

Work remains uncommitted on local branch `dev` at base HEAD `bf7a6893b525662e1edb56e9ac1e1f9d1e1ad828`.

The verified pre-Step-3 recovery checkpoint is `/tmp/cdm-ui2-step3-prechange.B0eHj6`:

- `tracked-working-tree.patch` — SHA-256 `1923a7435420a69b555641d161e3e8f2788906208055be8095facbfbcc36e671`
- `untracked-files.tar.gz` — SHA-256 `4c049db688aa495c8c53ee11321353e3f20f3c7bb191b5985c2bf369abcfff27`

Existing Git stashes were not applied, dropped, or modified.

## Professor Settings

The Professor website navigation rail and account menu now expose `/professor/settings`. The route is authorized only for `Professor` and declares `platforms: ['web']`; role and client-platform guards prevent Guest, Student, Admin, Registrar, and Android access. Student Settings remains at `/settings`, and Guest receives no Settings destination.

The interface uses only authenticated, self-scoped sources:

- `GET /me` for the current account and profile
- `GET /professor/dashboard`, protected by `role.professor` and `professor.web`, for faculty ID and department
- `POST /change-password` for the existing password-change flow and validation

Supported sections are Profile, Account, Appearance, and Security. Profile, faculty ID, department, contact details, account status, and last login are read-only. There is no Professor self-service profile-update endpoint, so Step 3 does not simulate editing or add an unrelated API. Password changes and their server errors are real. Appearance changes are persisted locally per authenticated account.

Student Settings keeps its existing server-backed profile, contact, preference, document-requirement, avatar, and security behavior.

## Appearance behavior

`website-ui-2.css` owns the semantic website tokens for canvas, surfaces, inputs, primary and muted text, borders, focus, disabled states, overlay, skeleton, and success/warning/danger/information states. `emerald-academic-web.css` maps academic modules to those tokens. The dark palette uses the approved values, including `#0b1613`, `#13241d`, `#1d3529`, `#e5ece8`, `#a7b8ad`, `#34d399`, and `#294133`.

- Light: uses the Emerald Academic light palette.
- Dark: applies the explicit website dark token set.
- System: uses `prefers-color-scheme`, so operating-system changes update without a reload.
- Initial render: `bootstrapPortalAppearance()` restores the account-scoped preference before Vue mounts.
- Account isolation: keys include role and authenticated account ID/username; one account cannot inherit another account's local choice.
- Student preferences remain server-backed and are also cached per account for pre-render restoration.
- Professor preferences are local to the browser/account because no server appearance endpoint exists; they do not sync between devices.

Coverage includes the website rail, contextual panel, mobile drawer, topbar, popovers, dashboards, Enrollment/Subjects/Schedule/COR preview, Grades, Monitoring/AI Help, Admission/examination, Document Requests, Settings, status states, loading/empty states, grade tables, forms, and teleported dialogs. CDM Buddy's speech card consumes the same semantic tokens.

Print media forces a light color scheme, white background, and dark text. Existing COR and academic report print-specific rules remain unchanged.

## Website-only isolation

The new shell and its CSS are still mounted only when `VITE_CDM_CLIENT=web`. Mobile and desktop continue through the legacy `DashboardLayout` branch. Professor Settings is also filtered out of non-web navigation and rejected by route metadata on Android. Shared authentication and APIs were not forked.

## Step 3 files

Added:

- `CDM_Frontend/src/composables/portalAppearance.js`
- `CDM_Frontend/test/websiteUiTwoStepThree.test.js`
- `docs/UI_2_STEP_3.md`

Updated for Step 3:

- `CDM_Frontend/src/main.js`
- `CDM_Frontend/src/composables/useStudentTheme.js`
- `CDM_Frontend/src/config/accessControl.js`
- `CDM_Frontend/src/config/websiteNavigation.js`
- `CDM_Frontend/src/stores/navigation.js`
- `CDM_Frontend/src/router/index.js`
- `CDM_Frontend/src/components/website/WebsiteNavigationRail.vue`
- `CDM_Frontend/src/components/website/WebsiteTopbar.vue`
- `CDM_Frontend/src/views/SettingsView.vue`
- `CDM_Frontend/src/components/CDMBuddy.vue`
- `CDM_Frontend/src/assets/styles/website-ui-2.css`
- `CDM_Frontend/src/assets/styles/emerald-academic-web.css`
- `CDM_Frontend/src/assets/styles/website-academic-modules.css`
- `CDM_Frontend/src/modules/dashboard/websiteDashboard.css`
- `CDM_Frontend/test/registrarDarkMode.test.js`
- `CDM_Frontend/test/registrarSettings.test.js`
- `CDM_Frontend/test/studentSidebarAvatar.test.js`

Other dirty files belong to the preserved Buddy/UI 2.0/academic work that existed before Step 3.

## Validation results

- Frontend suite: `npm test` — 46/46 test files passed.
- Relevant Laravel regressions: Authentication, Dashboard Summary, and Platform Access Control — 38 tests, 209 assertions passed using the in-memory SQLite test database.
- Website production build: passed with inert build-time `VITE_API_BASE_URL=https://api.example.invalid/api`.
- Android mobile-development build: passed.
- Electron production renderer and Linux packaging: passed; produced `release/CDM Portal-0.1.0.AppImage`. Packaging emitted only existing metadata recommendations for `desktopName` and Linux category.
- `git diff --check`: passed.
- No migrations, database commands, deployment, Git history changes, commits, pushes, or merges were performed.

Automated coverage verifies Professor-only/web-only routing, Student Settings preservation, account-scoped restore, no cross-account leakage, pre-mount theme bootstrap, Light/Dark/System selectors, live System media behavior, semantic token coverage, dark status contrast, forms/tables, teleported dialog overlays, Buddy compatibility, website-only isolation, platform authorization, and existing academic workflows.

## Manual UAT checklist

Use separate Student, Guest, and Professor test accounts in a local website session.

1. Professor: confirm Settings appears in the rail and account popover; open Profile, Account, Appearance, and Security.
2. Professor: verify only the signed-in identity, faculty ID, and department appear; confirm profile data is visibly read-only.
3. Professor: submit an incorrect and then correct current password; confirm server validation and success states.
4. Student: confirm existing Settings sections, avatar, preferences, record requirements, and password workflow remain available.
5. Guest, Admin, and Registrar: confirm Professor Settings is absent; directly opening `#/professor/settings` must show Unauthorized.
6. Android build: as Professor, confirm Professor Settings is absent and direct navigation is rejected.
7. For Student and Professor accounts, choose Light, Dark, and System; reload and navigate between modules after each selection.
8. Use two accounts with different themes and confirm switching accounts restores each account's own choice.
9. With System selected, change the operating-system color scheme while the portal is open and confirm immediate visual update.
10. In dark mode, inspect rail/panel/topbar/popovers, all dashboards, Enrollment, Subjects, Schedule, Grades, Monitoring/AI Help, Admission/exam, Document Requests, grading/bulk entry, and Settings.
11. Open validation, confirmation, appointment, grade, examination, and step-up dialogs; inspect focus rings, disabled controls, errors, status badges, overlays, and keyboard navigation.
12. Open CDM Buddy on Student and Guest pages; verify the speech bubble, notice actions, mute, docking, and acknowledgment controls in all three appearance modes.
13. Print-preview a COR and any supported academic report/export; confirm white paper, dark text, and institutional formatting.
14. At narrow mobile-browser and desktop widths, confirm no covered actions, unreadable tables, or horizontal page overflow.

## Remaining manual-review items

No compatible local browser executable was available, so Step 3 does not include new automated screenshots or pixel-level visual comparisons. Manual browser UAT is still required for visual polish, native select rendering, print preview, and real operating-system live-theme switching. Professor profile editing remains intentionally unavailable until a separately approved, authorized backend endpoint exists.
