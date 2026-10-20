<?php

namespace App\Http\Requests;

use App\Models\CabinetSlot;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class UpdateCabinetSlotRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'slot_code' => trim((string) $this->input('slot_code')),
            'description' => filled($this->input('description'))
                ? trim((string) $this->input('description'))
                : null,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $slot = $this->route('cabinetSlot');
        $cabinetId = $slot instanceof CabinetSlot ? $slot->cabinet_id : null;
        $slotId = $slot instanceof CabinetSlot ? $slot->id : $slot;

        return [
            'slot_code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('cabinet_slots', 'slot_code')
                    ->where(fn (Builder $query): Builder => $query->where('cabinet_id', $cabinetId))
                    ->ignore($slotId),
            ],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'size' => ['required', 'string', Rule::in(['small', 'medium', 'large', 'wide'])],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
