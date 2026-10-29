<?php

namespace App\Http\Requests\Academics;

use App\Enums\ProgramState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicProgramStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['required', 'string', Rule::in(ProgramState::values())],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}

