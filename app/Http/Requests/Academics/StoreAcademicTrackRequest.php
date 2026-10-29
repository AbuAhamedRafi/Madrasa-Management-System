<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;

class StoreAcademicTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'program_id' => ['required', 'integer', 'exists:academic_programs,id'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name_bn' => ['required', 'string', 'max:160'],
            'name_en' => ['required', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

