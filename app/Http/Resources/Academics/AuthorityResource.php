<?php

namespace App\Http\Resources\Academics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthorityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_bn' => $this->name_bn,
            'name_en' => $this->name_en,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'levels_count' => $this->levels_count ?? $this->levels()->count(),
            'levels' => $this->whenLoaded('levels', fn () => EducationLevelResource::collection($this->levels)->resolve()),
        ];
    }
}
