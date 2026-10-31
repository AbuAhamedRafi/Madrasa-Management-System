<?php

namespace App\Http\Requests\Promotions;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePromotionBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer'],
            'action' => ['nullable', 'string', 'in:PROMOTE,REPEAT'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

