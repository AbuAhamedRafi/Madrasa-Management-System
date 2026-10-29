<?php

namespace App\Http\Resources\Academics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicOfferingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_period_id' => $this->academic_period_id,
            'program_class_id' => $this->program_class_id,
            'academic_track_id' => $this->academic_track_id,
            'is_active' => $this->is_active,
            'program_class' => $this->whenLoaded('programClass', fn () => $this->programClass ? [
                'id' => $this->programClass->id,
                'code' => $this->programClass->code,
                'name_bn' => $this->programClass->name_bn,
                'name_en' => $this->programClass->name_en,
                'stage_number' => $this->programClass->stage_number,
            ] : null),
            'track' => $this->whenLoaded('track', fn () => $this->track ? [
                'id' => $this->track->id,
                'code' => $this->track->code,
                'name_bn' => $this->track->name_bn,
                'name_en' => $this->track->name_en,
            ] : null),
            'sections' => $this->whenLoaded('sections', fn () => OfferingSectionResource::collection($this->sections)->resolve(), []),
        ];
    }
}

