<?php

namespace App\Support;

use App\Enums\InventoryStatus;
use App\Enums\MealOutcome;
use App\Enums\RatingValue;
use App\Models\Pet;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Suggests which in-stock product to offer next, using only what the user has recorded about this pet.
 *
 * Evidence per product = the pet's last RECENT recorded outcomes for it plus the user's own rating.
 * There is no nutrition or health input anywhere in this class, by design.
 *
 * score = smoothed acceptance rate over recent outcomes (untried = 0.5), nudged by the user's own rating.
 * Default pick = highest score. Shuffle = weighted random among candidates (higher score, better odds), so
 * a refused product can still come up occasionally and an untried one gets a chance as a trial.
 * The product offered at the last meal is skipped when there is any other candidate.
 */
class MealSuggester
{
    public const RECENT = 5;

    /**
     * @param  list<int>  $excludeProductIds  products the user already passed on this round ("not that one")
     * @return array<string,mixed>
     */
    public function suggest(Pet $pet, bool $shuffle = false, array $excludeProductIds = [], ?int $seed = null): array
    {
        $items = $pet->user->inventoryItems()
            ->whereIn('status', [InventoryStatus::Stocked->value, InventoryStatus::Low->value])
            ->with('product')->get()
            ->filter(fn ($i) => $i->product && $i->product->species === $pet->species);

        $offers = $pet->mealOffers()->orderByDesc('offered_at')->orderByDesc('id')->get();
        $lastOfferedProductId = $offers->first()?->product_id;
        $recordedByProduct = $offers->whereNotNull('outcome')->groupBy('product_id');
        $lastOfferedAt = $offers->groupBy('product_id')->map(fn ($g) => $g->first()->offered_at);
        $ratings = $pet->ratings()->get()->keyBy('product_id');

        $candidates = $items->map(function ($item) use ($recordedByProduct, $lastOfferedAt, $ratings, $lastOfferedProductId, $pet) {
            $recent = ($recordedByProduct[$item->product_id] ?? collect())->take(self::RECENT)->values();
            $n = $recent->count();
            $ateAll = $recent->where('outcome', MealOutcome::AteAll)->count();
            $ateSome = $recent->where('outcome', MealOutcome::AteSome)->count();
            $refused = $recent->where('outcome', MealOutcome::Refused)->count();
            $rating = $ratings->get($item->product_id)?->rating;

            $acceptance = ($ateAll + 0.5 * $ateSome + 1) / ($n + 2); // Laplace-smoothed; untried = 0.5
            $adjust = match ($rating) {
                RatingValue::Liked => 0.10,
                RatingValue::Refused => -0.25,
                default => 0.0,
            };
            $score = max(0.02, min(1.0, $acceptance + $adjust));

            return [
                'product' => $item->product,
                'inventory_item_id' => $item->id,
                'inventory_status' => $item->status->value,
                'trial' => $n === 0,
                'offered_last_meal' => $item->product_id === $lastOfferedProductId,
                'last_offered_at' => $lastOfferedAt->get($item->product_id),
                'score' => round($score, 3),
                'evidence' => [
                    'outcomes_recorded' => $n,
                    'ate_all' => $ateAll,
                    'ate_some' => $ateSome,
                    'refused' => $refused,
                    'recent_outcomes' => $recent->map(fn ($o) => $o->outcome->value)->all(),
                    'your_rating' => $rating?->value,
                ],
                'statement' => $this->statement($pet->name, $n, $ateAll, $ateSome, $refused, $rating),
            ];
        })->values();

        $excluded = array_flip($excludeProductIds);
        $available = $candidates->reject(fn ($c) => isset($excluded[$c['product']->id]))->values();

        if ($available->isEmpty()) {
            return $this->result($pet, null, [], $candidates->isEmpty() ? 'nothing_in_stock' : 'all_passed');
        }

        // Skip the product offered at the last meal when anything else is available.
        $pool = $available->reject(fn ($c) => $c['offered_last_meal'])->values();
        if ($pool->isEmpty()) {
            $pool = $available;
        }

        $pick = $shuffle ? $this->weightedPick($pool, $seed) : $this->bestOf($pool);

        $alternatives = $available->reject(fn ($c) => $c['product']->id === $pick['product']->id)
            ->sortByDesc('score')->values()->all();

        return $this->result($pet, $pick, $alternatives, null);
    }

    /** @param \Illuminate\Support\Collection<int,array<string,mixed>> $pool */
    private function bestOf($pool): array
    {
        // Highest score; ties go to the product offered least recently (never offered first), then by id.
        return $pool->sort(function ($a, $b) {
            return [$b['score'], $a['last_offered_at']?->timestamp ?? 0, $a['product']->id]
                <=> [$a['score'], $b['last_offered_at']?->timestamp ?? 0, $b['product']->id];
        })->first();
    }

    /** @param \Illuminate\Support\Collection<int,array<string,mixed>> $pool */
    private function weightedPick($pool, ?int $seed): array
    {
        $random = $seed === null ? new Randomizer : new Randomizer(new Mt19937($seed));
        $weights = $pool->map(fn ($c) => $c['score'] ** 2)->all();
        $roll = $random->getFloat(0.0, array_sum($weights));

        foreach ($pool->values() as $i => $candidate) {
            $roll -= $weights[$i];
            if ($roll <= 0) {
                return $candidate;
            }
        }

        return $pool->last();
    }

    private function statement(string $pet, int $n, int $ateAll, int $ateSome, int $refused, ?RatingValue $rating): string
    {
        if ($n === 0) {
            $s = "Nothing recorded yet for {$pet} with this one, so this would be a trial.";
        } elseif ($n === 1) {
            $what = $ateAll ? 'ate all of it' : ($ateSome ? 'ate some of it' : 'refused it');
            $s = "Based on what you've recorded: {$pet} {$what} the one time you offered it.";
        } else {
            $parts = [];
            if ($ateAll) {
                $parts[] = "ate all {$ateAll}";
            }
            if ($ateSome) {
                $parts[] = "ate some {$ateSome}";
            }
            if ($refused) {
                $parts[] = "refused {$refused}";
            }
            $s = "Based on what you've recorded: {$pet} ".implode(', ', $parts)." of the last {$n} times you offered it.";
        }

        if ($rating) {
            $s .= " You rated it: {$rating->value}.";
        }

        return $s;
    }

    /** @param list<array<string,mixed>> $alternatives @return array<string,mixed> */
    private function result(Pet $pet, ?array $pick, array $alternatives, ?string $reason): array
    {
        return [
            'pet' => ['id' => $pet->id, 'name' => $pet->name, 'species' => $pet->species],
            'suggestion' => $pick,
            'alternatives' => $alternatives,
            'none_reason' => $reason, // nothing_in_stock | all_passed | null
            'basis' => config('catfud.suggestion_basis'),
        ];
    }
}
