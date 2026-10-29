<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAcademicCohortRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'program_id' => ['sometimes', 'integer', 'exists:academic_programs,id'],
            'label' => ['sometimes', 'required', 'string', 'max:64'],
            'intake_year' => ['sometimes', 'integer', 'min:1990', 'max:2100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }
}

