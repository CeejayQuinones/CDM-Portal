<?php

namespace App\Services;

use App\Models\Professor;
use App\Models\RegistrarStaff;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccountProvisioningService
{
    public function __construct(private readonly AccountAuditWriter $audit) {}

    /** @param array<string, mixed> $data */
    public function createRegistrar(User $actor, array $data): RegistrarStaff
    {
        return DB::transaction(function () use ($actor, $data): RegistrarStaff {
            $user = $this->createUser($data, Role::REGISTRAR_STAFF);
            $profile = $this->createProfile($user, $data);
            $registrar = RegistrarStaff::query()->create([
                'user_id' => $user->id,
                'user_profile_id' => $profile->id,
                'employee_number' => $data['employee_number'],
                'position' => $data['position'],
                'employment_status' => $data['employment_status'],
                'status' => $data['status'],
            ]);
            $this->audit->record($actor, $user, 'registrar_account_created', [
                'role' => Role::REGISTRAR_STAFF,
                'registrar_staff_id' => $registrar->id,
            ]);

            return $registrar->load('user.profile');
        });
    }

    /** @param array<string, mixed> $data */
    public function createProfessor(User $actor, array $data): Professor
    {
        return DB::transaction(function () use ($actor, $data): Professor {
            $user = $this->createUser($data, Role::PROFESSOR);
            $profile = $this->createProfile($user, $data);
            $professor = Professor::query()->create([
                'user_id' => $user->id,
                'user_profile_id' => $profile->id,
                'department_id' => $data['department_id'],
                'employee_number' => $data['employee_number'],
                'position' => $data['position'] ?? null,
                'specialization' => $data['specialization'] ?? null,
                'employment_status' => $data['employment_status'],
                'status' => $data['status'],
            ]);
            $this->audit->record($actor, $user, 'professor_account_created', [
                'role' => Role::PROFESSOR,
                'professor_id' => $professor->id,
                'department_id' => $professor->department_id,
            ]);

            return $professor->load('user.profile');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateRegistrar(User $actor, RegistrarStaff $registrar, array $data): RegistrarStaff
    {
        return DB::transaction(function () use ($actor, $registrar, $data): RegistrarStaff {
            $registrar = RegistrarStaff::query()->lockForUpdate()->findOrFail($registrar->id);
            $this->updateIdentity($registrar->user()->lockForUpdate()->firstOrFail(), $data);
            $registrar->update([
                'employee_number' => $data['employee_number'],
                'position' => $data['position'],
                'employment_status' => $data['employment_status'],
            ]);
            $this->audit->record($actor, $registrar->user, 'registrar_account_updated', [
                'role' => Role::REGISTRAR_STAFF,
                'registrar_staff_id' => $registrar->id,
            ]);

            return $registrar->fresh()->load('user.profile');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateProfessor(User $actor, Professor $professor, array $data): Professor
    {
        return DB::transaction(function () use ($actor, $professor, $data): Professor {
            $professor = Professor::query()->lockForUpdate()->findOrFail($professor->id);
            $this->updateIdentity($professor->user()->lockForUpdate()->firstOrFail(), $data);
            $professor->update([
                'department_id' => $data['department_id'],
                'employee_number' => $data['employee_number'],
                'position' => $data['position'] ?? null,
                'specialization' => $data['specialization'] ?? null,
                'employment_status' => $data['employment_status'],
            ]);
            $this->audit->record($actor, $professor->user, 'professor_account_updated', [
                'role' => Role::PROFESSOR,
                'professor_id' => $professor->id,
                'department_id' => $professor->department_id,
            ]);

            return $professor->fresh()->load('user.profile');
        });
    }

    public function setRegistrarStatus(User $actor, RegistrarStaff $registrar, string $status): RegistrarStaff
    {
        return $this->setStatus($actor, $registrar, $status, 'registrar_account_status_changed');
    }

    public function setProfessorStatus(User $actor, Professor $professor, string $status): Professor
    {
        return $this->setStatus($actor, $professor, $status, 'professor_account_status_changed');
    }

    /** @param array<string, mixed> $data */
    private function createUser(array $data, string $roleName): User
    {
        $role = Role::query()->where('role_name', $roleName)->firstOrFail();

        return User::query()->create([
            'username' => $data['username'],
            'password' => Hash::make($data['password']),
            'role_id' => $role->id,
            'status' => $data['status'],
            'is_first_login' => true,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function createProfile(User $user, array $data)
    {
        return $user->profile()->create($this->profileData($data));
    }

    /** @param array<string, mixed> $data */
    private function updateIdentity(User $user, array $data): void
    {
        $updates = ['username' => $data['username']];
        if (filled($data['password'] ?? null)) {
            $updates['password'] = Hash::make($data['password']);
            $updates['is_first_login'] = true;
        }
        $user->update($updates);
        $user->profile()->lockForUpdate()->firstOrFail()->update($this->profileData($data));
    }

    /** @param array<string, mixed> $data */
    private function profileData(array $data): array
    {
        return [
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?? null,
            'gender' => $data['gender'],
            'birth_date' => $data['birth_date'] ?? null,
            'email' => $data['email'],
            'contact_number' => $data['contact_number'] ?? null,
            'address' => $data['address'] ?? null,
            'nationality' => $data['nationality'] ?? 'Filipino',
        ];
    }

    private function setStatus(User $actor, RegistrarStaff|Professor $record, string $status, string $action): RegistrarStaff|Professor
    {
        return DB::transaction(function () use ($actor, $record, $status, $action): RegistrarStaff|Professor {
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            $user = $record->user()->lockForUpdate()->firstOrFail();
            $previous = $record->status;
            $user->update(['status' => $status]);
            $record->update(['status' => $status]);
            $this->audit->record($actor, $user, $action, [
                'role' => $user->role()->value('role_name'),
                'from' => $previous,
                'to' => $status,
            ]);

            return $record->fresh()->load('user.profile');
        });
    }
}
