<?php

namespace App\Http\Resources\Academics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_bn' => $this->name_bn,
            'name_en' => $this->name_en,
            'description_bn' => $this->description_bn,
            'description_en' => $this->description_en,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'programs_count' => $this->programs_count ?? $this->programs()->count(),
            'programs' => $this->whenLoaded('programs', fn () => $this->programs->map(fn ($p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name_bn' => $p->name_bn,
                'name_en' => $p->name_en,
                'scheme' => $p->scheme->value,
                'state' => $p->state->value,
            ])->values()),
        ];
    }
}
