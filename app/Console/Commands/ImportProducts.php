<?php

namespace App\Console\Commands;

use App\Support\ProductImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('products:import {file : Path to a .csv or .json file} {--dry-run : Validate and report without writing}')]
#[Description('Import or update products from a CSV or JSON file (upserts by GTIN)')]
class ImportProducts extends Command
{
    public function handle(ProductImporter $importer): int
    {
        $dry = (bool) $this->option('dry-run');

        try {
            $result = $importer->importFile((string) $this->argument('file'), $dry);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dry ? '[dry run] ' : '')."created {$result['created']}, updated {$result['updated']}, skipped (already audited) {$result['skipped']}, errors ".count($result['errors']));

        foreach ($result['errors'] as $err) {
            $this->warn("row {$err['row']} (gtin ".($err['gtin'] ?? 'n/a').'): '.implode('; ', $err['messages']));
        }

        return $result['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
