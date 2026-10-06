<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;

/** Records created / updated / deleted with the acting user and, for updates, the before and after of each changed field. */
trait Auditable
{
    /** Columns that churn without meaning (derived caches and timestamps) and would only add noise. */
    protected static array $auditIgnore = ['updated_at', 'search_text', 'path_text', 'formula_changed_at'];

    public static function bootAuditable(): void
    {
        static::created(function ($m) {
            AuditLog::record('created', $m, collect($m->getAttributes())->only(['name', 'brand', 'gtin', 'kind', 'moderation_status', 'source', 'parent_id'])->filter(fn ($v) => $v !== null)->all());
        });
        static::updated(function ($m) {
            $changes = [];
            foreach ($m->getChanges() as $field => $new) {
                if (! in_array($field, static::$auditIgnore, true)) {
                    $changes[$field] = [$m->getOriginal($field), $new];
                }
            }
            if ($changes) {
                AuditLog::record('updated', $m, $changes);
            }
        });
        static::deleted(function ($m) {
            AuditLog::record('deleted', $m, ['name' => $m->getAttribute('name')]);
        });
    }
}
