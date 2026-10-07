<?php

namespace App\Console\Commands;

use App\Support\Catalogue\CatalogueFiles;
use App\Support\Catalogue\CatalogueImporter;
use App\Support\Catalogue\CatalogueValidator;
use App\Support\Region;
use Illuminate\Console\Command;
use Throwable;

class CatalogueImport extends Command
{
    protected $signature = 'catalogue:import {brand? : A product file slug (e.g. science-diet). Imports its ladder first.} {--ladders : Import ladder files only (all, or the root named by the argument)} {--all : Every ladder and every product file} {--overwrite : Let the files overwrite fields on rungs that already exist} {--allow-unreviewed} {--dry-run} {--region=US}';

    protected $description = 'Load catalogue files into the database, one brand at a time. Refuses files that do not check clean or are not marked reviewed.';

    public function handle(): int
    {
        Region::set($this->option('region'));
        $files = new CatalogueFiles(Region::current());
        $slug = $this->argument('brand');
        $dry = (bool) $this->option('dry-run');
        $allow = (bool) $this->option('allow-unreviewed');

        if (! $slug && ! $this->option('all') && ! $this->option('ladders')) {
            $this->error('Name a brand file (catalogue:import science-diet), or pass --ladders or --all.');

            return self::FAILURE;
        }

        $slugs = $slug ? [(string) $slug] : ($this->option('all') ? array_keys($files->products()) : []);
        $check = (new CatalogueValidator($files))->check($slugs ?: null);
        $errors = array_filter($check['issues'], fn ($i) => $i['level'] === 'error');
        if ($errors) {
            foreach ($errors as $i) {
                $this->error($i['file'].($i['where'] ? ' ['.$i['where'].'] ' : ' ').$i['message']);
            }
            $this->error(count($errors).' error(s). Nothing was imported. Fix the files and run catalogue:check.');

            return self::FAILURE;
        }

        $importer = new CatalogueImporter($files, new CatalogueValidator($files), app(\App\Support\BrandTreeMapper::class), app(\App\Support\ImageMirror::class));
        try {
            if ($this->option('ladders')) {
                $r = $importer->importLadders($slug ? [(string) $slug] : null, (bool) $this->option('overwrite'), $allow, $dry);
                $this->info(($dry ? '[dry run] ' : '')."rungs: {$r['nodes_created']} created, {$r['nodes_updated']} updated");

                return self::SUCCESS;
            }
            if ($this->option('all')) {
                $l = $importer->importLadders(null, (bool) $this->option('overwrite'), $allow, $dry);
                $this->info(($dry ? '[dry run] ' : '')."rungs: {$l['nodes_created']} created, {$l['nodes_updated']} updated");
            }
            foreach ($slugs as $s) {
                $r = $importer->importProducts($s, $allow, $dry);
                $this->info(($dry ? '[dry run] ' : '')."{$s}: {$r['created']} created, {$r['updated']} updated, {$r['unchanged']} unchanged, {$r['skipped']} skipped (already audited)");
                foreach ($r['conflicts'] as $c) {
                    $this->warn('  conflict: '.$c);
                }
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
