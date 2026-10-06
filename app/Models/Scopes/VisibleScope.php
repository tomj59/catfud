<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Who can see a product. Everyone sees the approved catalogue. A signed-in user also sees what they contributed (pending,
 * needs changes, rejected) so their app keeps working while it is reviewed; merged duplicates are re-pointed, never shown.
 * Commands and imports (no signed-in user) see everything. The admin tool opts out with withoutGlobalScope(VisibleScope::class).
 */
class VisibleScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }
        $builder->where(function (Builder $q) use ($model, $user) {
            $q->where($model->qualifyColumn('moderation_status'), 'approved')
                ->orWhere(fn ($own) => $own->where($model->qualifyColumn('created_by'), $user->getKey())
                    ->whereIn($model->qualifyColumn('moderation_status'), ['pending', 'needs_changes', 'rejected']));
        });
    }
}
