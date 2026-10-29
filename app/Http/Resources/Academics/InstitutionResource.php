<?php

namespace App\Http\Resources\Academics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstitutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_bn' => $this->name_bn,
            'name_en' => $this->name_en,
            'address_bn' => $this->address_bn,
            'address_en' => $this->address_en,
            'phone' => $this->phone,
            'logo_url' => $this->logo_path,
            'default_locale' => $this->default_locale,
            'timezone' => $this->timezone,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

