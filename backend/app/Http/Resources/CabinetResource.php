<?php

namespace App\Http\Resources;

use App\Models\Cabinet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Cabinet */
class CabinetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $slots = $this->relationLoaded('slots') ? $this->slots : collect();

        return [
            'id' => $this->id,
            'cabinet_code' => $this->cabinet_code,
            'description' => $this->description,
            'rows' => $this->rows,
            'columns' => $this->columns,
            'slots_count' => (int) ($this->slots_count ?? $slots->count()),
            'occupied_slots_count' => $slots->where('student_record_locations_count', '>', 0)->count(),
            'slots' => $this->whenLoaded('slots', fn () => $slots->map(fn ($slot) => [
                'id' => $slot->id,
                'slot_code' => $slot->slot_code,
                'capacity' => $slot->capacity,
                'record_count' => (int) $slot->student_record_locations_count,
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
