<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Professor;
use App\Models\RegistrarStaff;
use App\Models\User;
use App\Services\AccountProvisioningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AccountProvisioningController extends Controller
{
    public function __construct(private readonly AccountProvisioningService $provisioning) {}

    public function registrars(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $accounts = RegistrarStaff::query()->with('user.profile')
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where(function (Builder $nested) use ($search): void {
                $nested->where('employee_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $user) => $user->where('username', 'like', "%{$search}%")
                        ->orWhereHas('profile', fn (Builder $profile) => $profile->where('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")));
            }))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest('id')->paginate(20)->withQueryString();

        return $this->ok(['accounts' => $this->accounts($accounts)]);
    }

    public function storeRegistrar(Request $request): JsonResponse
    {
        $registrar = $this->provisioning->createRegistrar($this->actor($request), $this->validated($request, $this->registrarRules()));

        return $this->ok(['account' => $this->account($registrar)], 201, 'Registrar Staff account created.');
    }

    public function updateRegistrar(Request $request, RegistrarStaff $registrarStaff): JsonResponse
    {
        $registrar = $this->provisioning->updateRegistrar($this->actor($request), $registrarStaff, $this->validated($request, $this->registrarRules($registrarStaff)));

        return $this->ok(['account' => $this->account($registrar)], 200, 'Registrar Staff account updated.');
    }

    public function registrarStatus(Request $request, RegistrarStaff $registrarStaff): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $registrar = $this->provisioning->setRegistrarStatus($this->actor($request), $registrarStaff, $data['status']);

        return $this->ok(['account' => $this->account($registrar)], 200, 'Registrar Staff account status updated.');
    }

    public function professors(Request $request): JsonResponse
    {
        $filters = $this->filters($request, true);
        $accounts = Professor::query()->with('user.profile')
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where(function (Builder $nested) use ($search): void {
                $nested->where('employee_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $user) => $user->where('username', 'like', "%{$search}%")
                        ->orWhereHas('profile', fn (Builder $profile) => $profile->where('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")));
            }))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['department_id'], fn (Builder $query, int $departmentId) => $query->where('department_id', $departmentId))
            ->latest('id')->paginate(20)->withQueryString();

        return $this->ok([
            'accounts' => $this->accounts($accounts),
            'departments' => Department::query()->where('status', 'active')->orderBy('department_name')->get(['id', 'department_code', 'department_name']),
        ]);
    }

    public function storeProfessor(Request $request): JsonResponse
    {
        $professor = $this->provisioning->createProfessor($this->actor($request), $this->validated($request, $this->professorRules()));

        return $this->ok(['account' => $this->account($professor)], 201, 'Professor account created.');
    }

    public function updateProfessor(Request $request, Professor $professor): JsonResponse
    {
        $professor = $this->provisioning->updateProfessor($this->actor($request), $professor, $this->validated($request, $this->professorRules($professor)));

        return $this->ok(['account' => $this->account($professor)], 200, 'Professor account updated.');
    }

    public function professorStatus(Request $request, Professor $professor): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $professor = $this->provisioning->setProfessorStatus($this->actor($request), $professor, $data['status']);

        return $this->ok(['account' => $this->account($professor)], 200, 'Professor account status updated.');
    }

    /** @return array<string, mixed> */
    private function registrarRules(?RegistrarStaff $registrar = null): array
    {
        return $this->commonRules($registrar?->user_id, $registrar?->user_profile_id, $registrar === null) + [
            'employee_number' => ['required', 'string', 'max:20', Rule::unique('registrar_staff')->ignore($registrar?->id)],
            'position' => ['required', 'string', 'max:100'],
            'employment_status' => ['required', Rule::in(['regular', 'contractual', 'part_time'])],
            'status' => [$registrar === null ? 'required' : 'prohibited', Rule::in(['active', 'inactive'])],
        ];
    }

    /** @return array<string, mixed> */
    private function professorRules(?Professor $professor = null): array
    {
        return $this->commonRules($professor?->user_id, $professor?->user_profile_id, $professor === null) + [
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('status', 'active')],
            'employee_number' => ['required', 'string', 'max:20', Rule::unique('professors')->ignore($professor?->id)],
            'position' => ['nullable', 'string', 'max:100'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'employment_status' => ['required', Rule::in(['full_time', 'part_time', 'contractual'])],
            'status' => [$professor === null ? 'required' : 'prohibited', Rule::in(['active', 'inactive'])],
        ];
    }

    /** @return array<string, mixed> */
    private function commonRules(?int $userId, ?int $profileId, bool $creating): array
    {
        return [
            'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('users')->ignore($userId)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('user_profiles')->ignore($profileId)],
            'password' => [$creating ? 'required' : 'nullable', 'string', Password::min(8), 'confirmed'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'gender' => ['required', Rule::in(['Male', 'Female', 'Prefer not to say'])],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'nationality' => ['nullable', 'string', 'max:50'],
            'role' => ['prohibited'],
            'role_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'user_profile_id' => ['prohibited'],
            'google_subject_id' => ['prohibited'],
        ];
    }

    /** @return array{search: string|null, status: string|null, department_id?: int|null} */
    private function filters(Request $request, bool $withDepartment = false): array
    {
        $rules = [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
        if ($withDepartment) {
            $rules['department_id'] = ['nullable', 'integer', 'exists:departments,id'];
        }
        $data = $request->validate($rules);

        return [
            'search' => filled($data['search'] ?? null) ? trim($data['search']) : null,
            'status' => $data['status'] ?? null,
            ...($withDepartment ? ['department_id' => isset($data['department_id']) ? (int) $data['department_id'] : null] : []),
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $request->merge([
            'username' => trim((string) $request->input('username')),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        return $request->validate($rules);
    }

    private function accounts(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        return $paginator->through(fn (RegistrarStaff|Professor $account) => $this->account($account));
    }

    /** @return array<string, mixed> */
    private function account(RegistrarStaff|Professor $record): array
    {
        $record->loadMissing('user.profile');
        $profile = $record->user->profile;

        return [
            'id' => $record->id,
            'user_id' => $record->user_id,
            'username' => $record->user->username,
            'email' => $profile?->email,
            'first_name' => $profile?->first_name,
            'middle_name' => $profile?->middle_name,
            'last_name' => $profile?->last_name,
            'suffix' => $profile?->suffix,
            'gender' => $profile?->gender,
            'birth_date' => $profile?->birth_date?->toDateString(),
            'contact_number' => $profile?->contact_number,
            'address' => $profile?->address,
            'nationality' => $profile?->nationality,
            'employee_number' => $record->employee_number,
            'position' => $record->position,
            'employment_status' => $record->employment_status,
            'status' => $record->status,
            'account_status' => $record->user->status,
            ...($record instanceof Professor ? [
                'department_id' => $record->department_id,
                'specialization' => $record->specialization,
            ] : []),
        ];
    }

    private function ok(array $data, int $status = 200, string $message = 'Accounts retrieved.'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
