# CDM Portal security headers

## Bearer token lifetime

Set `SANCTUM_TOKEN_EXPIRATION=1440` in the backend deployment environment for a 24-hour lifetime. Only positive integer minutes are accepted; an invalid value fails configuration loading, and an invalid cached value prevents new token issuance. Keep the value consistent across all backend workers, rebuild Laravel's configuration cache when changing it, and reload workers through the approved deployment procedure. Existing bearer tokens are also checked against the global Sanctum lifetime. Do not set this value to zero or leave a stale cached configuration in service.

The Vite build injects a client-aware Content Security Policy into `index.html`. Production builds allow only the built application's own resources, `data:`/`blob:` resources required by image and PDF previews, and the configured HTTPS API origin. Development builds additionally allow loopback HTTP and WebSocket connections for Vite and the local Laravel API.

Electron and Capacitor consume the same generated policy. Electron continues to enforce `contextIsolation: true`, `nodeIntegration: false`, external-navigation blocking, and the file-based application entry point.

## Pending deployment validation

Cloudflare Pages should eventually send the policy as an HTTP response header after validating it in report-only mode. No production setting was changed by this repair. The proposed response header is the generated production policy plus:

```text
frame-ancestors 'none'; upgrade-insecure-requests
```

Also retain these response headers at the hosting layer:

```text
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(self), microphone=(), geolocation=()
```

Before enforcement, confirm Login, Google authentication exchange, authenticated API calls, image/document previews, OVRF PDF preview, PWA installation/update, Android WebView, and packaged Electron behavior. Do not add wildcard sources to work around a failed check; add only the exact required origin and directive.

## Manual cross-platform validation checklist

Use test accounts and a non-production API. On each platform, record the build mode, API origin, observed HTTP status, console CSP messages, and screenshot where relevant.

- Website and installed PWA: sign in and out; confirm protected views become inaccessible after logout. Complete Google sign-in and its callback/exchange when configured. Let a test bearer token expire, then verify the next protected request returns 401, clears local auth, and redirects to login without a redirect loop. Change a password and verify this device and another pre-existing session both receive 401 on their next request.
- Website and PWA: open authenticated document images, retry a failed preview, open and print PDF previews, download Enrollment/COR/OVRF files, and confirm object URLs and `blob:` frames work. Install/update the PWA and repeat while online; check service worker and browser consoles for blocked resources.
- Android debug and release WebView: repeat login/logout, token expiry, password-change, document image, PDF, and Enrollment/OVRF download flows. In release, verify HTTPS-only API access; debug-only loopback allowances must not appear in release. Inspect WebView console/network logs for CSP and mixed-content failures.
- Packaged Electron (not just Vite renderer): repeat login/logout and expiration, password change, image/PDF preview and print, and Enrollment/OVRF download. Check DevTools console for blocked `file:`, `blob:`, API, or print-frame resources; verify external navigation remains blocked and preload isolation remains enabled.
- For each browser/WebView/Electron context, review every CSP violation with the blocked URL and directive. A successful build is not evidence of runtime CSP compatibility. Validate the final website policy in `Content-Security-Policy-Report-Only` before enforcing it.

The HTML `<meta http-equiv="Content-Security-Policy">` policy protects the bundled app, but cannot enforce `frame-ancestors` or CSP reporting directives such as `report-to`; those require HTTP response headers from the hosting layer. The proposed website `frame-ancestors 'none'` and `upgrade-insecure-requests` must be reviewed and sent as response headers with the final policy. This repository does not activate Cloudflare/Nginx headers. Apply equivalent header review to API responses separately; the HTML meta policy does not set headers on Laravel responses or downloaded PDFs.
