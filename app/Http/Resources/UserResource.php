<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The signed-in account. @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /** @return array{id:int, name:string, email:string, role:string, region:string} */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'role' => $this->role ?? 'user', 'region' => $this->region ?? 'US'];
    }
}
