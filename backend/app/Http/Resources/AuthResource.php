<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class AuthResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'status' => $this->status,
            'is_first_login' => $this->is_first_login,
            'last_login' => $this->last_login?->toISOString(),
            'role' => $this->whenLoaded('role', fn () => [
                'id' => $this->role->id,
                'role_name' => $this->role->role_name,
                'description' => $this->role->description,
            ]),
            'profile' => $this->whenLoaded('profile', fn () => [
                'id' => $this->profile->id,
                'first_name' => $this->profile->first_name,
                'middle_name' => $this->profile->middle_name,
                'last_name' => $this->profile->last_name,
                'suffix' => $this->profile->suffix,
                'gender' => $this->profile->gender,
                'birth_date' => $this->profile->birth_date?->toDateString(),
                'civil_status' => $this->profile->civil_status,
                'email' => $this->profile->email,
                'contact_number' => $this->profile->contact_number,
                'address' => $this->profile->address,
                'profile_photo' => $this->profile->profile_photo,
                'nationality' => $this->profile->nationality,
            ]),
        ];
    }
}
