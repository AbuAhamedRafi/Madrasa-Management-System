<?php

namespace App\Http\Requests\Academics;

use App\Enums\CohortStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicCohortStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(CohortStatus::values())],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}

