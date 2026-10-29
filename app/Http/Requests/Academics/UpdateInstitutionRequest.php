<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInstitutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:32'],
            'name_bn' => ['sometimes', 'required', 'string', 'max:180'],
            'name_en' => ['sometimes', 'required', 'string', 'max:180'],
            'address_bn' => ['nullable', 'string', 'max:1000'],
            'address_en' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:40'],
            'default_locale' => ['sometimes', 'string', Rule::in(['bn-BD', 'en'])],
            'timezone' => ['sometimes', 'string', Rule::in(['Asia/Dhaka'])],
        ];
    }
}

