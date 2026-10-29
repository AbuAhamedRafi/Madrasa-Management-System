<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;

class StoreAcademicPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'program_id' => ['required', 'integer', 'exists:academic_programs,id'],
            'cohort_id' => ['nullable', 'integer', 'exists:academic_cohorts,id'],
            'label' => ['required', 'string', 'max:120'],
            'calendar_year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
            'study_year_number' => ['nullable', 'integer', 'min:1', 'max:12'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }
}

