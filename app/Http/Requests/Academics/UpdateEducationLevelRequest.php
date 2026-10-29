<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEducationLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'authority_id' => ['sometimes', 'integer', 'exists:education_authorities,id'],
            'code' => ['sometimes', 'string', 'max:24', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name_bn' => ['sometimes', 'required', 'string', 'max:160'],
            'name_en' => ['sometimes', 'required', 'string', 'max:160'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}

