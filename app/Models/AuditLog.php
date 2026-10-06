<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Who changed what, and when. Written automatically for products and brand nodes, and explicitly for bulk operations. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'changes', 'note'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /** @param  array<string,mixed>|null  $changes */
    public static function record(string $action, Model|string $subject, ?array $changes = null, ?string $note = null, ?int $subjectId = null): self
    {
        return self::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject instanceof Model ? class_basename($subject) : $subject,
            'subject_id' => $subject instanceof Model ? $subject->getKey() : $subjectId,
            'changes' => $changes,
            'note' => $note,
        ]);
    }
}
