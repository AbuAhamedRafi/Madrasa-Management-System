<?php

namespace App\Http\Requests\UserManagement;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $targetUser = $this->route('user');
        $userId = is_object($targetUser) ? $targetUser->id : null;

        return [
            'name_bn' => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'name_en' => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,' . $userId],
            'phone' => ['nullable', 'string', 'max:25'],
        ];
    }
}

