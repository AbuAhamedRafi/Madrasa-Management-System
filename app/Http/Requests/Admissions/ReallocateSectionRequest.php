<?php

namespace App\Http\Requests\Admissions;

use Illuminate\Foundation\Http\FormRequest;

class ReallocateSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_section_id' => ['required', 'integer', 'exists:offering_sections,id'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}

