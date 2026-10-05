<?php

namespace App\Support;

use App\Models\Advisory;
use App\Models\AdvisoryMatch;
use App\Models\User;
use Illuminate\Support\Collection;

/** Shapes advisories for the API: always attributed, never a verdict. */
final class AdvisoryPresenter
{
    /**
     * @param  Collection<int,int>|null  $onlyProductIds  keep only matches for these products (the user's pantry)
     * @return array<string,mixed>
     */
    public static function advisory(Advisory $a, User $user, ?Collection $onlyProductIds = null): array
    {
        $matches = $a->matches->when($onlyProductIds, fn ($c) => $c->filter(fn ($m) => $onlyProductIds->contains($m->product_id)));

        return [
            'id' => $a->id,
            'attribution' => self::attribution($a),
            'source_name' => $a->source_name,
            'source_url' => $a->source_url,
            'published_at' => $a->published_at?->toDateString(),
            'ingested_at' => $a->ingested_at?->toIso8601String(),
            'topic' => $a->topic,
            'source_text' => $a->source_text,
            'matches' => $matches->map(fn ($m) => self::match($m, $user))->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    public static function match(AdvisoryMatch $m, User $user): array
    {
        $review = $m->reviews->firstWhere('user_id', $user->id);

        return [
            'id' => $m->id,
            'product' => $m->product ? ['id' => $m->product->id, 'brand' => $m->product->brand, 'name' => $m->product->name, 'gtin' => $m->product->gtin] : null,
            'match_basis' => $m->match_basis->value,
            'confidence' => $m->confidence->value,
            'match_detail' => $m->match_detail,
            'review' => ['status' => $review?->status->value ?? 'new', 'note' => $review?->note],
        ];
    }

    /** Advisories that may relate to one product, each with its own attribution. @return list<array<string,mixed>> */
    public static function forProduct(int $productId, User $user): array
    {
        return AdvisoryMatch::where('product_id', $productId)
            ->with(['advisory', 'product', 'reviews'])->get()
            ->map(fn ($m) => [
                'advisory_id' => $m->advisory_id,
                'attribution' => self::attribution($m->advisory),
                'source_name' => $m->advisory->source_name,
                'source_url' => $m->advisory->source_url,
                'published_at' => $m->advisory->published_at?->toDateString(),
                'topic' => $m->advisory->topic,
                'source_text' => $m->advisory->source_text,
            ] + ['match' => self::match($m, $user)])->values()->all();
    }

    public static function attribution(Advisory $a): string
    {
        $date = $a->published_at ? ' on '.$a->published_at->toFormattedDateString() : '';

        return "Reported by {$a->source_name}{$date}";
    }
}
