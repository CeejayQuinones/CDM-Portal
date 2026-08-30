<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadStudentDocumentRequest extends FormRequest
{
    public const MAX_FILE_SIZE_MB = 10;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'pdf'])->max(self::MAX_FILE_SIZE_MB.'mb'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Select a document to upload.',
            'file.mimes' => 'The document must be a JPEG, PNG, or PDF file.',
            'file.max' => 'The document must not be larger than '.self::MAX_FILE_SIZE_MB.' MB.',
        ];
    }
}
