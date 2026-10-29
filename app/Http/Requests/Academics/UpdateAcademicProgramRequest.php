<?php

namespace App\Http\Requests\Academics;

use App\Enums\AcademicScheme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:32'],
            'department_id' => ['sometimes', 'integer', 'exists:departments,id'],
            'education_level_id' => ['sometimes', 'integer', 'exists:education_levels,id'],
            'name_bn' => ['sometimes', 'required', 'string', 'max:180'],
            'name_en' => ['sometimes', 'required', 'string', 'max:180'],
            'description_bn' => ['nullable', 'string', 'max:1000'],
            'description_en' => ['nullable', 'string', 'max:1000'],
            'scheme' => ['sometimes', 'string', Rule::in(AcademicScheme::values())],
            'duration_years' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ];
    }
}

