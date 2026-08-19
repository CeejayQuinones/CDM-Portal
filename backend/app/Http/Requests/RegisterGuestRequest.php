<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class RegisterGuestRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|\Illuminate\Validation\Rules\Password>> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:user_profiles,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'gender' => ['required', Rule::in(['Male', 'Female', 'Prefer not to say'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
            'nationality' => ['nullable', 'string', 'max:50'],
        ];
    }
}
