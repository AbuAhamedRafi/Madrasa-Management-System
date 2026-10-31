<?php

namespace App\Http\Requests\Students;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentIdentifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('students.manage_identifiers') ?? false;
    }

    public function rules(): array
    {
        return [
            'namespace' => ['required', 'string', 'max:64'],
            'value' => ['required', 'string', 'max:128'],
            'issuer' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}

