<?php

namespace App\Console\Commands;

use App\Support\Catalogue\CatalogueExporter;
use App\Support\Catalogue\CatalogueFiles;
use App\Support\Region;
use Illuminate\Console\Command;

class CatalogueExport extends Command
{
    protected $signature = 'catalogue:export {--region=US} {--force : Overwrite files already marked reviewed}';

    protected $description = 'Write the database out as catalogue files (vocabulary, one ladder per root, one product file per brand) for review.';

    public function handle(): int
    {
        Region::set($this->option('region'));
        $files = new CatalogueFiles(Region::current());
        $r = (new CatalogueExporter($files))->export((bool) $this->option('force'));
        $this->info(count($r['written']).' file(s) written to '.$files->dir());
        foreach ($r['kept'] as $k) {
            $this->warn('kept (already reviewed): '.str_replace(database_path().'/', '', $k));
        }

        return self::SUCCESS;
    }
}
