<?php

namespace App\Http\Requests\Academics;

use App\Enums\CohortStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicCohortRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'program_id' => ['required', 'integer', 'exists:academic_programs,id'],
            'label' => ['required', 'string', 'max:64'],
            'intake_year' => ['required', 'integer', 'min:1990', 'max:2100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'status' => ['sometimes', 'string', Rule::in(CohortStatus::values())],
        ];
    }
}

