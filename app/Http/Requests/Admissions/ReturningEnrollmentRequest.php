<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReturningEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_record_id' => ['required', 'integer', 'exists:students,id'],
            'academic_program_id' => ['required', 'integer', 'exists:academic_programs,id'],
            'academic_period_id' => ['required', 'integer', 'exists:academic_periods,id'],
            'offering_section_id' => ['required', 'integer', 'exists:offering_sections,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($this->has('student_id')) {
                $validator->errors()->add(
                    'student_id',
                    'Supplying display student_id is strictly prohibited. You must supply student_record_id.'
                );
            }
        });
    }
}
