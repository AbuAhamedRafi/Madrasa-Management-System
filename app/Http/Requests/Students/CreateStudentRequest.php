<?php

namespace App\Http\Requests\Students;

use Illuminate\Foundation\Http\FormRequest;

class CreateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('students.create') ?? false;
    }

    public function rules(): array
    {
        return [
            // Prohibit server-generated / lifecycle fields
            'student_id' => ['prohibited'],
            'status' => ['prohibited'],

            'name_bn' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'gender' => ['required', 'string', 'in:MALE,FEMALE'],
            'admission_date' => ['nullable', 'date'],
            'birth_registration_number' => ['nullable', 'string', 'regex:/^[0-9]{17}$/'],
            'present_address_bn' => ['nullable', 'string'],
            'present_address_en' => ['nullable', 'string'],
            'permanent_address_bn' => ['nullable', 'string'],
            'permanent_address_en' => ['nullable', 'string'],
            'profile_photo_path' => ['nullable', 'string', 'max:512'],

            // Primary Guardian (mandatory)
            'primary_guardian' => ['required', 'array'],
            'primary_guardian.id' => ['nullable', 'integer', 'exists:guardians,id'],
            'primary_guardian.name_bn' => ['required_without:primary_guardian.id', 'nullable', 'string', 'max:255'],
            'primary_guardian.name_en' => ['required_without:primary_guardian.id', 'nullable', 'string', 'max:255'],
            'primary_guardian.phone' => ['required_without:primary_guardian.id', 'nullable', 'string', 'max:32'],
            'primary_guardian.alternative_phone' => ['nullable', 'string', 'max:32'],
            'primary_guardian.relation' => ['required', 'string', 'in:FATHER,MOTHER,LEGAL_GUARDIAN,OTHER'],
            'primary_guardian.sms_consent' => ['nullable', 'boolean'],
            'primary_guardian.consent_source' => ['nullable', 'string', 'max:64'],
            'primary_guardian.address_bn' => ['nullable', 'string'],
            'primary_guardian.address_en' => ['nullable', 'string'],
            'primary_guardian.occupation' => ['nullable', 'string', 'max:128'],

            // Secondary Guardians (optional)
            'secondary_guardians' => ['nullable', 'array'],
            'secondary_guardians.*.id' => ['nullable', 'integer', 'exists:guardians,id'],
            'secondary_guardians.*.name_bn' => ['required_without:secondary_guardians.*.id', 'nullable', 'string', 'max:255'],
            'secondary_guardians.*.name_en' => ['required_without:secondary_guardians.*.id', 'nullable', 'string', 'max:255'],
            'secondary_guardians.*.phone' => ['required_without:secondary_guardians.*.id', 'nullable', 'string', 'max:32'],
            'secondary_guardians.*.alternative_phone' => ['nullable', 'string', 'max:32'],
            'secondary_guardians.*.relation' => ['required', 'string', 'in:FATHER,MOTHER,LEGAL_GUARDIAN,OTHER'],
            'secondary_guardians.*.sms_consent' => ['nullable', 'boolean'],
            'secondary_guardians.*.consent_source' => ['nullable', 'string', 'max:64'],

            // Identifiers (optional)
            'identifiers' => ['nullable', 'array'],
            'identifiers.*.namespace' => ['required', 'string', 'max:64'],
            'identifiers.*.value' => ['required', 'string', 'max:128'],
            'identifiers.*.issuer' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.prohibited' => 'Student ID is system-generated and cannot be provided manually.',
            'status.prohibited' => 'Student status cannot be set manually during admission intake.',
            'primary_guardian.required' => 'At least one primary guardian is required.',
            'birth_registration_number.regex' => 'The birth registration number must be exactly 17 digits.',
        ];
    }
}

