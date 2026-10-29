<?php

namespace App\Http\Resources\Academics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferingSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_offering_id' => $this->academic_offering_id,
            'offering_id' => $this->academic_offering_id,
            'code' => $this->code,
            'name_bn' => $this->name_bn,
            'name_en' => $this->name_en,
            'capacity' => $this->capacity,
            'is_active' => $this->is_active,
        ];
    }
}
