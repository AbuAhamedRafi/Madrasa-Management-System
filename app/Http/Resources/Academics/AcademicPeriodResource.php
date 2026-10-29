<?php

namespace App\Http\Resources\Academics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'program_id' => $this->program_id,
            'cohort_id' => $this->cohort_id,
            'label' => $this->label,
            'calendar_year' => $this->calendar_year,
            'study_year_number' => $this->study_year_number,
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date->format('Y-m-d'),
            'state' => $this->state->value,
            'lock_version' => $this->lock_version,
            'updated_at' => $this->updated_at?->toISOString(),
            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'code' => $this->program->code,
                'name_bn' => $this->program->name_bn,
                'name_en' => $this->program->name_en,
                'scheme' => $this->program->scheme->value,
            ] : null),
            'cohort' => $this->whenLoaded('cohort', fn () => $this->cohort ? [
                'id' => $this->cohort->id,
                'label' => $this->cohort->label,
                'intake_year' => $this->cohort->intake_year,
                'status' => $this->cohort->status->value,
            ] : null),
            'offerings' => $this->whenLoaded('offerings', fn () => $this->offerings->map(fn ($o) => [
                'id' => $o->id,
                'program_class_id' => $o->program_class_id,
                'academic_track_id' => $o->academic_track_id,
                'class_name_bn' => $o->programClass?->name_bn,
                'class_name_en' => $o->programClass?->name_en,
                'track_name_bn' => $o->track?->name_bn,
                'track_name_en' => $o->track?->name_en,
                'is_active' => $o->is_active,
                'sections_count' => $o->sections->count(),
            ])->values()),
        ];
    }
}

