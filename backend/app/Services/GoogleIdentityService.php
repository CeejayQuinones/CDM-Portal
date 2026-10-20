<?php

namespace App\Services;

use App\Exceptions\GoogleIdentityException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GoogleIdentityService
{
    public function __construct(private readonly ClientPlatformAccessService $clientAccess) {}

    public function authenticate(string $subject, string $email, bool $verified, string $client): User
    {
        if (! $verified) {
            throw new GoogleIdentityException('unverified_email', 'Your Google email must be verified before you can sign in.');
        }

        $subject = trim($subject);
        $email = mb_strtolower(trim($email));
        if ($subject === '' || $email === '') {
            throw new GoogleIdentityException('missing_google_identity', 'No eligible CDM Portal account is associated with this Google account.');
        }

        return DB::transaction(function () use ($subject, $email, $client) {
            $user = User::query()
                ->with(['role', 'profile'])
                ->whereHas('profile', fn ($query) => $query->whereRaw('LOWER(TRIM(email)) = ?', [$email]))
                ->lockForUpdate()
                ->first();

            if (! $user) {
                throw new GoogleIdentityException('no_existing_account', 'No eligible CDM Portal account is associated with this Google account.');
            }
            if (! in_array($user->role?->role_name, [Role::PROFESSOR, Role::STUDENT], true)) {
                throw new GoogleIdentityException('unsupported_role', 'No eligible CDM Portal account is associated with this Google account.');
            }
            if ($user->status !== 'active') {
                throw new GoogleIdentityException('inactive_user', 'This account is not active.');
            }
            if ($user->google_subject_id !== null && ! hash_equals($user->google_subject_id, $subject)) {
                throw new GoogleIdentityException('google_subject_mismatch', 'No eligible CDM Portal account is associated with this Google account.');
            }
            if (User::query()->where('google_subject_id', $subject)->whereKeyNot($user->id)->exists()) {
                throw new GoogleIdentityException('google_subject_already_linked', 'No eligible CDM Portal account is associated with this Google account.');
            }

            $this->clientAccess->ensureUserMayUse($user, $client);
            if ($user->google_subject_id === null) {
                $user->forceFill(['google_subject_id' => $subject, 'google_email' => $email, 'google_linked_at' => now()])->save();
                Log::info('google_identity_linked', ['user_id' => $user->id, 'client' => $client]);
            }

            return $user->fresh(['role', 'profile']);
        });
    }
}
