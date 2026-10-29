<?php

namespace App\Http\Resources\Academics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'department_id' => $this->department_id,
            'education_level_id' => $this->education_level_id,
            'name_bn' => $this->name_bn,
            'name_en' => $this->name_en,
            'description_bn' => $this->description_bn,
            'description_en' => $this->description_en,
            'scheme' => $this->scheme->value,
            'duration_years' => $this->duration_years,
            'state' => $this->state->value,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'code' => $this->department->code,
                'name_bn' => $this->department->name_bn,
                'name_en' => $this->department->name_en,
            ] : null),
            'education_level' => $this->whenLoaded('educationLevel', fn () => $this->educationLevel ? [
                'id' => $this->educationLevel->id,
                'code' => $this->educationLevel->code,
                'name_bn' => $this->educationLevel->name_bn,
                'name_en' => $this->educationLevel->name_en,
                'authority' => $this->educationLevel->relationLoaded('authority') && $this->educationLevel->authority ? [
                    'id' => $this->educationLevel->authority->id,
                    'code' => $this->educationLevel->authority->code,
                    'name_bn' => $this->educationLevel->authority->name_bn,
                    'name_en' => $this->educationLevel->authority->name_en,
                ] : null,
            ] : null),
        ];
    }
}

