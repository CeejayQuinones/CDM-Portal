<?php

namespace App\Services;

use App\Models\Professor;
use App\Models\RegistrarStaff;
use App\Models\Role;
use App\Models\Subject;
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

    /** @param list<int> $subjectIds */
    public function syncProfessorSubjects(User $actor, Professor $professor, array $subjectIds): Professor
    {
        return DB::transaction(function () use ($actor, $professor, $subjectIds): Professor {
            $professor = Professor::query()->lockForUpdate()->findOrFail($professor->id);
            $selectedIds = collect($subjectIds)->map(fn (int $id): int => $id)->unique()->sort()->values();
            $previousIds = $professor->subjects()->pluck('subjects.id')->map(fn (int $id): int => $id)->sort()->values();
            $newIds = $selectedIds->diff($previousIds)->values();

            if ($newIds->isNotEmpty()) {
                abort_if($professor->status !== 'active', 422, 'Activate the Professor before adding subject associations.');
                $activeCount = Subject::query()->whereIn('id', $newIds)->where('status', 'active')->lockForUpdate()->count();
                abort_if($activeCount !== $newIds->count(), 422, 'Only active subjects may be newly associated.');
            }

            $professor->subjects()->sync($selectedIds->all());
            $this->audit->record($actor, $professor->user, 'professor_subjects_updated', [
                'role' => Role::PROFESSOR,
                'professor_id' => $professor->id,
                'previous_subject_ids' => $previousIds->all(),
                'subject_ids' => $selectedIds->all(),
            ]);

            return $professor->fresh()->load(['user.profile', 'subjects']);
        }, 3);
    }

    /** @param list<int> $professorIds */
    public function syncSubjectProfessors(User $actor, Subject $subject, array $professorIds): Subject
    {
        return DB::transaction(function () use ($actor, $subject, $professorIds): Subject {
            $subject = Subject::query()->lockForUpdate()->findOrFail($subject->id);
            $selectedIds = collect($professorIds)->map(fn (int $id): int => $id)->unique()->sort()->values();
            $previousIds = $subject->professors()->pluck('professors.id')->map(fn (int $id): int => $id)->sort()->values();
            $newIds = $selectedIds->diff($previousIds)->values();
            $changedIds = $selectedIds->diff($previousIds)->merge($previousIds->diff($selectedIds))->unique()->sort()->values();
            $professors = Professor::query()->with(['user.role', 'subjects'])->whereIn('id', $selectedIds->merge($previousIds))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($newIds->isNotEmpty()) {
                abort_if($subject->status !== 'active', 422, 'Reactivate the subject before adding Professor associations.');
                foreach ($newIds as $professorId) {
                    $professor = $professors->get($professorId);
                    abort_unless($professor?->status === 'active' && $professor->user?->status === 'active' && $professor->user?->role?->role_name === Role::PROFESSOR, 422, 'Only active Professor accounts may be newly associated.');
                }
            }

            $subject->professors()->sync($selectedIds->all());
            foreach ($changedIds as $professorId) {
                $professor = $professors->get($professorId);
                if (! $professor) {
                    continue;
                }
                $before = $professor->subjects->pluck('id')->map(fn (int $id): int => $id)->sort()->values();
                $after = $before->reject(fn (int $id): bool => $id === $subject->id);
                if ($selectedIds->contains($professorId)) {
                    $after->push($subject->id);
                }
                $this->audit->record($actor, $professor->user, 'professor_subjects_updated', [
                    'role' => Role::PROFESSOR,
                    'professor_id' => $professor->id,
                    'previous_subject_ids' => $before->sort()->values()->all(),
                    'subject_ids' => $after->unique()->sort()->values()->all(),
                ]);
            }

            return $subject->fresh()->load('professors');
        }, 3);
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
