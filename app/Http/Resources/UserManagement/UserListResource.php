<?php

namespace App\Http\Resources\UserManagement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserListResource extends JsonResource
{
    /**
     * Transform the resource into an array matching SRS D01 Section 12.3.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id ?? (string) $this->id,
            'username' => $this->username,
            'name_bn' => $this->name_bn,
            'name_en' => $this->name_en,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->roles->first()?->key ?? 'OFFICE_STAFF',
            'roles' => $this->roles->pluck('key')->values()->all(),
            'status' => $this->status->value,
            'preferred_locale' => $this->preferred_locale->value,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}

