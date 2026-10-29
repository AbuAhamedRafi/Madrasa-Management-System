<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAuthorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:24', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name_bn' => ['sometimes', 'required', 'string', 'max:160'],
            'name_en' => ['sometimes', 'required', 'string', 'max:160'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}

