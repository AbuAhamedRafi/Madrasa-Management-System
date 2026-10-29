<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOfferingSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name_bn' => ['sometimes', 'required', 'string', 'max:120'],
            'name_en' => ['sometimes', 'required', 'string', 'max:120'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

