<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One entry in the change history. @mixin \App\Models\AuditLog */
class AuditLogResource extends JsonResource
{
    /**
     * @return array{id:int, action:string, subject_type:string, subject_id:?int, user_id:?int, user_name:?string,
     *   changes:?array<string,mixed>, note:?string, created_at:?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'action' => $this->action, 'subject_type' => $this->subject_type, 'subject_id' => $this->subject_id,
            'user_id' => $this->user_id, 'user_name' => $this->user?->name, 'changes' => $this->changes, 'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
