# Login email OTP deployment notes

Website/PWA and Android password or Google sign-in now require a six-digit email code before Laravel issues a Sanctum token. Electron Registrar/Admin login remains on the existing platform-bound password flow.

## Required deployment configuration

- Run the additive `2026_11_12_000001_create_login_otp_challenges_table.php` migration during an approved deployment window.
- Configure `MAIL_MAILER` with a working non-logging transport and valid sender settings. Login OTP deliberately rejects `log`, `array`, or a failover/round-robin chain containing either transport so raw codes cannot be written to application logs.
- Ensure every Website/Android account has an accurate email in `user_profiles.email`. Missing or malformed addresses fail closed and require an administrator correction.
- Keep `X-CDM-Client` set to `web` for Website/PWA and `mobile` for Android. Challenges and resulting tokens are platform-bound.
- Existing valid Sanctum tokens are not revoked by enabling OTP. They expire under the existing 24-hour policy. Password changes, staff password resets, account-status changes, and Guest-to-Student role transitions invalidate unfinished challenges.

## Operational behavior

- Code lifetime: 5 minutes.
- Resend cooldown: 60 seconds.
- Maximum code attempts per challenge: 5.
- A new login or resend invalidates the previous code.
- Account/IP request throttles and route throttles provide temporary lockouts.
- Mail failure rolls back challenge creation and never grants access.

Monitor `login_otp_delivery_failed` warning events for transport availability. The event contains only the exception class and never the email address or raw code.
