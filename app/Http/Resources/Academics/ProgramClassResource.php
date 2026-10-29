<?php

namespace App\Http\Resources\Academics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'program_id' => $this->program_id,
            'code' => $this->code,
            'name_bn' => $this->name_bn,
            'name_en' => $this->name_en,
            'stage_number' => $this->stage_number,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}

