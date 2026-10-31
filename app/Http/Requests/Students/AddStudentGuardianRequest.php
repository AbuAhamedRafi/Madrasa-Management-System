<?php

namespace App\Http\Requests\Students;

use Illuminate\Foundation\Http\FormRequest;

class AddStudentGuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('students.manage_guardians') ?? false;
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'integer', 'exists:guardians,id'],
            'name_bn' => ['required_without:id', 'nullable', 'string', 'max:255'],
            'name_en' => ['required_without:id', 'nullable', 'string', 'max:255'],
            'phone' => ['required_without:id', 'nullable', 'string', 'max:32'],
            'alternative_phone' => ['nullable', 'string', 'max:32'],
            'relation' => ['required', 'string', 'in:FATHER,MOTHER,LEGAL_GUARDIAN,OTHER'],
            'is_primary' => ['nullable', 'boolean'],
            'sms_consent' => ['nullable', 'boolean'],
            'consent_source' => ['nullable', 'string', 'max:64'],
            'address_bn' => ['nullable', 'string'],
            'address_en' => ['nullable', 'string'],
            'occupation' => ['nullable', 'string', 'max:128'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}

