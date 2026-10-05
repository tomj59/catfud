<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\BrandTreeMapper;
use App\Support\Region;
use Illuminate\Console\Command;

class BuildBrandTree extends Command
{
    protected $signature = 'catalogue:build-tree {--region=US : Region to process} {--force : Re-place products that already have a node} {--dry-run}';

    protected $description = 'Place existing products in the brand tree (brand > line > ...) and tag texture/medium. Safe to repeat; never touches names, barcodes or audit status.';

    public function handle(BrandTreeMapper $mapper): int
    {
        Region::set($this->option('region'));
        $dry = (bool) $this->option('dry-run');
        $placed = 0;

        $query = Product::query()->when(! $this->option('force'), fn ($q) => $q->whereNull('brand_node_id'));
        $query->orderBy('id')->each(function (Product $p) use ($mapper, $dry, &$placed) {
            if (! $dry) {
                $mapper->apply($p);
            }
            $placed++;
        });

        $this->info(($dry ? '[dry run] ' : '')."placed {$placed} products in the brand tree");

        return self::SUCCESS;
    }
}
