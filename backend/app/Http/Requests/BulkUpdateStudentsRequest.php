<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\Rule;

class BulkUpdateStudentsRequest extends ApiFormRequest
{
    public const CHANGE_STATUS = 'change_status';

    public const CHANGE_YEAR_LEVEL = 'change_year_level';

    public const CHANGE_COURSE = 'change_course';

    public const ASSIGN_RECORD_LOCATION = 'assign_record_location';

    public const UPDATE_DOCUMENT_AVAILABILITY = 'update_document_availability';

    public const ACTIONS = [
        self::CHANGE_STATUS,
        self::CHANGE_YEAR_LEVEL,
        self::CHANGE_COURSE,
        self::ASSIGN_RECORD_LOCATION,
        self::UPDATE_DOCUMENT_AVAILABILITY,
    ];

    private const REGISTRAR_ONLY_ACTIONS = [
        self::ASSIGN_RECORD_LOCATION,
        self::UPDATE_DOCUMENT_AVAILABILITY,
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $user->loadMissing('role');

        return ! in_array($this->input('action'), self::REGISTRAR_ONLY_ACTIONS, true)
            || $user->role?->role_name === Role::REGISTRAR_STAFF;
    }

    public function rules(): array
    {
        $rules = [
            'student_ids' => ['required', 'array', 'min:1', 'max:500'],
            'student_ids.*' => ['required', 'integer', 'distinct', 'exists:students,id'],
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'value' => ['required'],
        ];

        return match ($this->input('action')) {
            self::CHANGE_STATUS => [
                ...$rules,
                'value' => ['required', 'string', Rule::in([
                    'regular',
                    'irregular',
                    'graduated',
                    'transferred',
                    'dropped',
                    'leave_of_absence',
                ])],
            ],
            self::CHANGE_YEAR_LEVEL => [
                ...$rules,
                'value' => ['required', 'integer', 'between:1,20'],
            ],
            self::CHANGE_COURSE => [
                ...$rules,
                'value' => ['required', 'integer', 'exists:courses,id'],
            ],
            self::ASSIGN_RECORD_LOCATION => [
                ...$rules,
                'value' => ['required', 'array:cabinet_slot_id'],
                'value.cabinet_slot_id' => ['required', 'integer', 'exists:cabinet_slots,id'],
            ],
            self::UPDATE_DOCUMENT_AVAILABILITY => [
                ...$rules,
                'value' => ['required', 'array:document_type_id,availability_status'],
                'value.document_type_id' => ['required', 'integer', 'exists:document_types,id'],
                'value.availability_status' => ['required', 'string', Rule::in(['available', 'missing'])],
            ],
            default => $rules,
        };
    }
}
