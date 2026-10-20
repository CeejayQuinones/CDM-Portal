# Platform-aware access control

## Purpose

CDM Portal applies platform access in addition to its existing role permissions. The backend decides whether an authenticated role may use the declared client, so changing frontend navigation or manually calling an API cannot bypass the rule.

| Role | Desktop | Web | Mobile |
| --- | --- | --- | --- |
| Registrar Staff | Yes | No | No |
| Admin/System Admin | Yes | Yes | No |
| Student | No | Yes | Yes |
| Professor | Event Semi-Coordinator only | Yes | Yes |
| Guest | No | Public web only | No |

“Public web only” means a Guest can use public registration and verification routes and may sign in on the web for applicant self-service. Existing route policies still decide what that Guest can do after authentication.

Professor Desktop access is conditional on an active Event `semi_coordinator` assignment. Professor Mobile supports Event participation and assigned moderation. Web Event pages use the promotion-safe API; this does not remove normal Professor Web access to unrelated modules.

## Client identification

Every login and authenticated API request carries one build-owned header:

```http
X-CDM-Client: web
X-CDM-Client: desktop
X-CDM-Client: mobile
```

The normal Vite build selects `web`, the Electron build selects `desktop`, and the Capacitor build selects `mobile`. There is no user-facing setting for this value. Missing or unknown values are rejected. User-agent strings are not used because they are inconsistent and easy to change.

## Backend enforcement

`ClientPlatformAccessService` owns the valid role/client matrix and the safe rejection messages. Login first validates the header and credentials, then checks the authenticated role against the matrix. A forbidden combination returns HTTP 403 before a token or `last_login` update is created.

All Sanctum-protected API routes run `EnsureClientPlatformAccess` immediately after `auth:sanctum`. The middleware checks:

1. the account is still active;
2. the header is present and valid;
3. the current role is allowed on that client;
4. a bearer token contains the ability matching the request client.

Existing role middleware and policies run afterward. Platform access therefore cannot grant Admission, Enrollment, Student Management, Monitoring, or document permissions that the role did not already have.

## Sanctum token binding

Login issues exactly one platform ability with the bearer token:

```text
client:web
client:desktop
client:mobile
```

A token issued with `client:web` is rejected if it is replayed with `X-CDM-Client: desktop`, even when the role itself is allowed on both clients. Legacy or manually created bearer tokens without a matching client ability are also rejected. Sanctum stateful cookie sessions do not expose a personal-token record; the middleware still applies the account, header, and role checks to those requests.

## Public routes

Public registration and email verification remain public and are not placed behind authenticated platform middleware. Login requires the header because that is where the client-bound session begins. Guest logins are accepted only from the web.

## IT validation examples

- Registrar Staff + web: login returns 403 and creates no token. The user is directed to the CDM Desktop application.
- Registrar Staff + desktop: login succeeds with `client:desktop`; authenticated desktop requests succeed subject to the existing role checks.
- Student + desktop: login returns 403 before the desktop application can open a protected workspace.
- Student + mobile: login succeeds with `client:mobile`.
- Admin + web token replayed as desktop: request returns 403 because the header does not match the token ability.
- Student + valid web token calling a Registrar route: the platform check passes, then existing RBAC returns 403.
- Suspended account with an old token: authenticated requests return 403 because account status is rechecked on every request.

The header identifies the client build and token binding prevents a token from changing client classes after issuance. As with any HTTP header, a custom client can claim a value at login; the security boundary remains backend authentication, the role/client matrix, client-bound tokens, and route-level RBAC. For stronger device identity in a future deployment, managed-device certificates or attestation would be a separate infrastructure control.

## Registrar Settings

Registrar Staff can open **Settings** from the desktop navigation. Admin users can use the same route on their currently allowed desktop or web clients. The authenticated self-service endpoints allow only the account owner's `user_profiles` name, contact details, address, and profile photo to be updated. Password changes reuse the existing current-password-verified security endpoint.

Role, permissions, account status, employee number, registrar position/status, Student identity, and Admission or Enrollment ownership remain read-only. The Settings endpoints accept no target user identifier and remain inside `auth:sanctum`, `client.platform`, and Registrar/Admin role middleware.

Light, dark, and system appearance choices reuse the Student theme palette and application-level theme initializer. Student appearance remains backed by `student_settings`; Registrar and Admin appearance is a role-scoped local preference (`cdm-registrar-appearance` or `cdm-admin-appearance`) restored by the desktop/web client on startup.

Student profile images remain owned solely by `user_profiles.profile_photo`. Registrar-facing Student Records, Student Profile, Physical Records, and Document Request detail responses expose the current relative public URL as `profile_photo_url`; clients resolve it through the configured API origin and fall back to initials when unavailable.
