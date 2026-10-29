<?php

namespace App\Http\Requests\Academics;

use App\Enums\AcademicScheme;
use App\Enums\ProgramState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'education_level_id' => ['required', 'integer', 'exists:education_levels,id'],
            'name_bn' => ['required', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
            'description_bn' => ['nullable', 'string', 'max:1000'],
            'description_en' => ['nullable', 'string', 'max:1000'],
            'scheme' => ['required', 'string', Rule::in(AcademicScheme::values())],
            'duration_years' => ['required', 'integer', 'min:1', 'max:12'],
            'state' => ['sometimes', 'string', Rule::in(ProgramState::values())],
        ];
    }
}

