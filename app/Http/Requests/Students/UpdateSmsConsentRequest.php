<?php

namespace App\Http\Requests\Students;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSmsConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('students.manage_guardians') ?? false;
    }

    public function rules(): array
    {
        return [
            'sms_consent' => ['required', 'boolean'],
            'consent_source' => ['nullable', 'string', 'max:64'],
        ];
    }
}

