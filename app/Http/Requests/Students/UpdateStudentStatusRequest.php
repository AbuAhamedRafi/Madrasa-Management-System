<?php

namespace App\Http\Requests\Students;

use App\Enums\StudentStatus;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('students.manage_status') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:' . implode(',', StudentStatus::values())],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}

