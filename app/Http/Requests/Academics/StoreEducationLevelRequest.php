<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;

class StoreEducationLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'authority_id' => ['required', 'integer', 'exists:education_authorities,id'],
            'code' => ['required', 'string', 'max:24', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name_bn' => ['required', 'string', 'max:160'],
            'name_en' => ['required', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}

