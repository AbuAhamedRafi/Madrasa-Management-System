<?php

namespace App\Http\Requests\Promotions;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteIndividualPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'source_enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'target_offering_section_id' => ['required', 'integer', 'exists:offering_sections,id'],
            'target_academic_period_id' => ['required', 'integer', 'exists:academic_periods,id'],
            'action' => ['nullable', 'string', 'in:PROMOTE,REPEAT'],
            'academic_eligibility_confirmed' => ['required', 'accepted'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

