<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterGuestRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => trim((string) $this->input('username')),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|Password>> */
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
            'birth_date' => ['required', 'date', 'before:today'],
            'contact_number' => ['required', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:1000'],
            'nationality' => ['nullable', 'string', 'max:50'],
            'terms_accepted' => ['required', 'accepted'],
            'email_verification_token' => ['required', 'string', 'size:64'],
        ];
    }
}
