<?php

namespace App\Support;

use App\Enums\MatchBasis;
use App\Enums\MatchConfidence;
use App\Models\Advisory;
use App\Models\AdvisoryMatch;
use App\Models\Product;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Imports Public Advisories from a JSON file (entered by hand in Phase 1).
 *
 * An advisory records what a named source said. It cannot be saved without a source name and a source link, and the
 * app never writes advisory text of its own. Each entry may list `matches`, which are only suggestions about which
 * products the advisory may relate to; the user confirms or dismisses each one.
 *
 * Match specs (all optional, combinable):
 *   {"gtin": "...", "lot_code": "..."}  exact product (basis upc, or lot_code when a lot is named), confidence high
 *   {"brand": "...", "lot_code": "..."} every product of that brand, basis brand, confidence medium
 *   {"free_text": "..."}                products whose brand or name contains the text, basis free_text, confidence low
 */
class AdvisoryImporter
{
    /**
     * @return array{advisories:int, matches:int, errors:array<int,array{entry:int,messages:list<string>}>, warnings:list<string>}
     */
    public function importFile(string $path, bool $dryRun = false): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $entries = $decoded['advisories'] ?? $decoded;

        return $this->import(array_values(is_array($entries) ? $entries : []), $dryRun);
    }

    /** @param list<array<string,mixed>> $entries */
    public function import(array $entries, bool $dryRun = false): array
    {
        $result = ['advisories' => 0, 'matches' => 0, 'errors' => [], 'warnings' => []];

        foreach ($entries as $i => $entry) {
            $n = $i + 1;
            $v = Validator::make($entry, [
                'source_name' => ['required', 'string', 'max:255'],
                'source_url' => ['required', 'url', 'max:2048'],
                'source_text' => ['required', 'string', 'max:20000'],
                'published_at' => ['nullable', 'date'],
                'topic' => ['nullable', 'string', 'max:255'],
                'matches' => ['nullable', 'array'],
            ]);

            if ($v->fails()) {
                $result['errors'][] = ['entry' => $n, 'messages' => $v->errors()->all()];

                continue;
            }

            $result['advisories']++;
            if ($dryRun) {
                continue;
            }

            $advisory = Advisory::updateOrCreate(
                ['source_url' => $entry['source_url'], 'source_text' => $entry['source_text']],
                [
                    'source_name' => $entry['source_name'],
                    'published_at' => $entry['published_at'] ?? null,
                    'topic' => $entry['topic'] ?? null,
                ] + (Advisory::where('source_url', $entry['source_url'])->where('source_text', $entry['source_text'])->exists()
                    ? [] : ['ingested_at' => now()]),
            );

            // Several specs can hit the same product; keep only the strongest match per product so a broad
            // brand-level spec never overwrites an exact one.
            $best = [];
            foreach ($entry['matches'] ?? [] as $spec) {
                $found = $this->resolve($spec);
                if (! $found) {
                    $result['warnings'][] = "entry {$n}: no product matched ".json_encode($spec, JSON_UNESCAPED_UNICODE);

                    continue;
                }
                foreach ($found['products'] as $product) {
                    $rank = $this->rank($found['confidence']);
                    if (! isset($best[$product->id]) || $rank > $best[$product->id]['rank']) {
                        $best[$product->id] = ['rank' => $rank] + $found;
                    }
                }
            }

            foreach ($best as $productId => $m) {
                AdvisoryMatch::updateOrCreate(
                    ['advisory_id' => $advisory->id, 'product_id' => $productId],
                    ['match_basis' => $m['basis'], 'confidence' => $m['confidence'], 'match_detail' => $m['detail']],
                );
                $result['matches']++;
            }
        }

        return $result;
    }

    private function rank(MatchConfidence $c): int
    {
        return match ($c) {
            MatchConfidence::High => 3,
            MatchConfidence::Medium => 2,
            MatchConfidence::Low => 1,
        };
    }

    /** @param array<string,mixed> $spec @return array{products:\Illuminate\Support\Collection,basis:MatchBasis,confidence:MatchConfidence,detail:?string}|null */
    private function resolve(array $spec): ?array
    {
        $lot = isset($spec['lot_code']) && $spec['lot_code'] !== '' ? (string) $spec['lot_code'] : null;

        if (! empty($spec['gtin'])) {
            $product = Product::findByCode((string) $spec['gtin']);

            return $product ? [
                'products' => collect([$product]),
                'basis' => $lot ? MatchBasis::LotCode : MatchBasis::Upc,
                'confidence' => MatchConfidence::High,
                'detail' => $lot,
            ] : null;
        }

        if (! empty($spec['brand'])) {
            // A named brand matches at any level of the ladder, so an advisory about "Purina" covers every Purina brand.
            $needle = mb_strtolower((string) $spec['brand']);
            $esc = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle);
            $products = Product::where(fn ($q) => $q->whereRaw('lower(brand) = ?', [$needle])
                ->orWhereRaw('lower(path_text) = ?', [$needle])
                ->orWhereRaw("lower(path_text) like ? escape '!'", [$esc.' › %'])
                ->orWhereRaw("lower(path_text) like ? escape '!'", ['% › '.$esc.' › %'])
                ->orWhereRaw("lower(path_text) like ? escape '!'", ['% › '.$esc]))->get();

            return $products->isEmpty() ? null : [
                'products' => $products, 'basis' => $lot ? MatchBasis::LotCode : MatchBasis::Brand,
                'confidence' => MatchConfidence::Medium, 'detail' => $lot,
            ];
        }

        if (! empty($spec['free_text'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $spec['free_text']).'%';
            $products = Product::where(fn ($q) => $q->where('brand', 'like', $like)->orWhere('name', 'like', $like))->get();

            return $products->isEmpty() ? null : [
                'products' => $products, 'basis' => MatchBasis::FreeText,
                'confidence' => MatchConfidence::Low, 'detail' => (string) $spec['free_text'],
            ];
        }

        return null;
    }
}
