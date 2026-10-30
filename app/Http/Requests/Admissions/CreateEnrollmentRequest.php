<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateEnrollmentRequest extends FormRequest
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
            'academic_cohort_id' => ['nullable', 'integer', 'exists:academic_cohorts,id'],
            'entry_type' => ['nullable', 'string', 'in:REGULAR,TRANSFER_IN,LATERAL_ENTRY'],
            'enrollment_type' => ['nullable', 'string', 'in:NEW,CONTINUING'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            // INV-01: Prohibit passing display string student_id
            if ($this->has('student_id')) {
                $validator->errors()->add(
                    'student_id',
                    'Supplying display student_id is strictly prohibited. You must supply student_record_id.'
                );
            }
        });
    }
}
