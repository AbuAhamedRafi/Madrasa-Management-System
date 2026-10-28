<?php

namespace App\Http\Resources\RolePermission;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name_bn' => $this->name_bn,
            'name_en' => $this->name_en,
            'description_bn' => $this->description_bn,
            'description_en' => $this->description_en,
            'is_system' => $this->is_system,
            'permissions' => $this->permissions->map(fn ($p) => [
                'key' => $p->key,
                'access_level' => $p->pivot->access_level ?? 'ALLOWED',
            ]),
        ];
    }
}

