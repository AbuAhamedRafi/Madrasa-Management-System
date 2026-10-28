<?php

namespace App\Http\Requests\RolePermission;

use Illuminate\Foundation\Http\FormRequest;

class CreateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Z0-9_]+$/', 'unique:roles,key'],
            'name_en' => ['required', 'string', 'max:100'],
            'name_bn' => ['required', 'string', 'max:100'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'description_bn' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,key'],
        ];
    }
}

