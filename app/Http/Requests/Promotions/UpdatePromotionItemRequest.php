<?php

namespace App\Http\Requests\Promotions;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePromotionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_offering_section_id' => ['nullable', 'integer', 'exists:offering_sections,id'],
            'action' => ['nullable', 'string', 'in:PROMOTE,REPEAT'],
            'academic_eligibility_confirmed' => ['nullable', 'boolean'],
        ];
    }
}

