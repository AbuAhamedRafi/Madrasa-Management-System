<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProgramClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'program_id' => ['sometimes', 'integer', 'exists:academic_programs,id'],
            'code' => ['sometimes', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name_bn' => ['sometimes', 'required', 'string', 'max:160'],
            'name_en' => ['sometimes', 'required', 'string', 'max:160'],
            'stage_number' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}

