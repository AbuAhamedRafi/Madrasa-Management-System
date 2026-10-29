<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;

class StoreOfferingSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name_bn' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'capacity' => ['required', 'integer', 'min:1', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

