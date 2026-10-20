# Document request and appointment workflow

The active request states are `pending` and `approved`. The terminal states are `completed`, `rejected`, and `cancelled`.

Registrar Staff assigns one appointment date while the request remains pending. Approval confirms that appointment and generates a six-digit claim code. Only a keyed lookup digest and a slow password hash are stored; ordinary APIs never serialize either value. The plaintext code is passed only to the approval email. After successful server-side verification on the appointment date, Registrar Staff can complete or cancel the request and appointment atomically.

Daily capacity defaults to five and may be overridden per business date in `appointment_date_capacities`. Date validation observes Asia/Manila, weekend settings, holidays, blocked dates, and current capacity.

## Deployment

Apply the safe migration normally:

```bash
cd backend
php artisan migrate
```

Legacy `released` requests become `completed`. Legacy `processing` and `ready_for_release` requests return to `pending` for review and receive a system migration audit; no verification is inferred.

Run Laravel's scheduler continuously in development:

```bash
php artisan schedule:work
```

In production, invoke `php artisan schedule:run` every minute (or use the equivalent persistent scheduler integration supplied by the deployment platform). The scheduled `document-requests:cancel-no-shows` command runs daily at 00:10 Asia/Manila, uses row locks, and is idempotent.

After changing `APP_KEY`, previously issued claim-code lookup digests cannot be found. Rotate the application key only with an explicit claim-code reissue plan.
