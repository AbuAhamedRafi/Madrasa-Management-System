<?php

namespace App\Http\Resources\Academics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EducationLevelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'authority_id' => $this->authority_id,
            'code' => $this->code,
            'name_bn' => $this->name_bn,
            'name_en' => $this->name_en,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'authority' => $this->whenLoaded('authority', fn () => $this->authority ? [
                'id' => $this->authority->id,
                'code' => $this->authority->code,
                'name_bn' => $this->authority->name_bn,
                'name_en' => $this->authority->name_en,
            ] : null),
        ];
    }
}
