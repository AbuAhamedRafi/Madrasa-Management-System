<?php

namespace App\Http\Requests\UserProfile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_bn' => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'name_en' => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,' . $this->user()->id],
            'phone' => ['nullable', 'string', 'max:25'],
            'preferred_locale' => ['sometimes', 'required', 'string', 'in:bn-BD,en'],
        ];
    }

    /**
     * Get validated data including all raw inputs to detect unauthorized field injection.
     */
    public function allPayload(): array
    {
        return $this->all();
    }
}
