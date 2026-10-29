<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;

class StoreAcademicOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_period_id' => ['required', 'integer', 'exists:academic_periods,id'],
            'program_class_id' => ['required', 'integer', 'exists:program_classes,id'],
            'academic_track_id' => ['nullable', 'integer', 'exists:academic_tracks,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

