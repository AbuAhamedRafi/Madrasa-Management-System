<?php

namespace App\Http\Requests\Promotions;

use Illuminate\Foundation\Http\FormRequest;

class CreatePromotionBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['nullable', 'string', 'in:PROMOTE,REPEAT'],
            'mode' => ['nullable', 'string', 'in:BULK,INDIVIDUAL'],
            'source_academic_period_id' => ['required', 'integer', 'exists:academic_periods,id'],
            'source_offering_section_id' => ['nullable', 'integer', 'exists:offering_sections,id'],
            'target_academic_period_id' => ['required', 'integer', 'exists:academic_periods,id'],
            'target_academic_offering_id' => ['required', 'integer', 'exists:academic_offerings,id'],
            'default_target_section_id' => ['nullable', 'integer', 'exists:offering_sections,id'],
            'academic_eligibility_confirmed' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['integer', 'exists:students,id'],
        ];
    }
}

