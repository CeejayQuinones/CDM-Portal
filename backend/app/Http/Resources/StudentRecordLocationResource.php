<?php

namespace App\Http\Resources;

use App\Models\StudentRecordLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StudentRecordLocation */
class StudentRecordLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'assigned_at' => $this->assigned_at?->toISOString(),
            'remarks' => $this->remarks,
            'cabinet_slot' => $this->whenLoaded('cabinetSlot', fn () => [
                'id' => $this->cabinetSlot->id,
                'slot_code' => $this->cabinetSlot->slot_code,
                'capacity' => $this->cabinetSlot->capacity,
                'cabinet' => $this->cabinetSlot->relationLoaded('cabinet') ? [
                    'id' => $this->cabinetSlot->cabinet->id,
                    'cabinet_code' => $this->cabinetSlot->cabinet->cabinet_code,
                    'description' => $this->cabinetSlot->cabinet->description,
                    'rows' => $this->cabinetSlot->cabinet->rows,
                    'columns' => $this->cabinetSlot->cabinet->columns,
                ] : null,
            ]),
        ];
    }
}
