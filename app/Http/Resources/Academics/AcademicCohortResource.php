<?php

namespace App\Http\Resources\Academics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicCohortResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'program_id' => $this->program_id,
            'label' => $this->label,
            'intake_year' => $this->intake_year,
            'description' => $this->description,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'status' => $this->status->value,
            'periods_count' => $this->periods_count ?? $this->periods()->count(),
            'periods' => $this->whenLoaded('periods', fn () => AcademicPeriodResource::collection($this->periods)->resolve()),
        ];
    }
}
