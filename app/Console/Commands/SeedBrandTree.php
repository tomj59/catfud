<?php

namespace App\Console\Commands;

use App\Models\BrandNode;
use App\Support\BrandCatalogue;
use App\Support\Region;
use Illuminate\Console\Command;

class SeedBrandTree extends Command
{
    protected $signature = 'catalogue:seed-brands {--region=US : Region to seed} {--prune-empty : Also delete nodes that hold no products and no children and are not in the curated file} {--dry-run}';

    protected $description = 'Create the curated brand ladder (brands, lines, sub-lines, species) from database/seeds/brand_suggestions_*.json. Safe to repeat; fills gaps only and never renames or moves an existing node.';

    public function handle(): int
    {
        Region::set($this->option('region'));
        $dry = (bool) $this->option('dry-run');
        $max = (int) config('catfud.brand_tree_max_depth', 5);
        $before = BrandNode::count();
        $made = 0;

        foreach (BrandCatalogue::chains() as $chain) {
            if (count($chain) > $max) {
                $this->warn('Skipped (deeper than '.$max.'): '.implode(' > ', array_column($chain, 'name')));

                continue;
            }
            if ($dry) {
                $this->line(implode(' > ', array_column($chain, 'name')));

                continue;
            }
            BrandNode::ensurePath(array_map(fn ($e) => [
                'name' => $e['name'], 'kind' => $e['kind'] ?? null, 'aliases' => $e['aliases'] ?? null,
                'tags' => $e['tags'] ?? null, 'species' => $e['species'] ?? null,
            ], $chain));
        }

        $made = BrandNode::count() - $before;

        if ($this->option('prune-empty')) {
            $keep = [];
            foreach (BrandCatalogue::chains() as $chain) {
                $keep[mb_strtolower(Region::current()).'|'.implode('>', array_map(fn ($e) => mb_strtolower($e['name']), $chain))] = true;
            }
            // Deepest first, so a parent emptied by its children going is found in the same pass.
            foreach (BrandNode::orderByDesc('depth')->get() as $node) {
                if (isset($keep[$node->path_key]) || $node->products()->exists() || $node->children()->exists()) {
                    continue;
                }
                $this->line(($dry ? '[dry run] would remove ' : 'removed ').$node->path_key);
                if (! $dry) {
                    $node->delete();
                }
            }
        }
        $this->info($dry ? '[dry run] nothing written' : "seeded {$made} new brand nodes ({$before} already existed)");

        return self::SUCCESS;
    }
}
