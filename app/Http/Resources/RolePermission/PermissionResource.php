<?php

namespace App\Http\Resources\RolePermission;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'module' => $this->module,
            'label_bn' => $this->label_bn,
            'label_en' => $this->label_en,
            'description_bn' => $this->description_bn,
            'description_en' => $this->description_en,
            'display_order' => $this->display_order,
        ];
    }
}

