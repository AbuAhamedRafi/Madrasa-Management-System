<?php

namespace App\Http\Requests\Students;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('students.update') ?? false;
    }

    public function rules(): array
    {
        return [
            // student_id and status cannot be edited via general profile update
            'student_id' => ['prohibited'],
            'status' => ['prohibited'],

            'name_bn' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'required', 'date', 'before:today'],
            'gender' => ['sometimes', 'required', 'string', 'in:MALE,FEMALE'],
            'birth_registration_number' => ['nullable', 'string', 'regex:/^[0-9]{17}$/'],
            'present_address_bn' => ['nullable', 'string'],
            'present_address_en' => ['nullable', 'string'],
            'permanent_address_bn' => ['nullable', 'string'],
            'permanent_address_en' => ['nullable', 'string'],
            'profile_photo_path' => ['nullable', 'string', 'max:512'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.prohibited' => 'Student ID is permanent and cannot be modified.',
            'status.prohibited' => 'Student status must be changed through the dedicated status endpoint.',
        ];
    }
}

