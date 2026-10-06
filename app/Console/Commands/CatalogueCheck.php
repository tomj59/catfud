<?php

namespace App\Console\Commands;

use App\Support\Catalogue\CatalogueFiles;
use App\Support\Catalogue\CatalogueValidator;
use App\Support\Region;
use Illuminate\Console\Command;

class CatalogueCheck extends Command
{
    protected $signature = 'catalogue:check {brand? : A product file slug (e.g. science-diet); omit to check everything} {--region=US} {--warnings : Show warnings as well as errors} {--summary : Counts per file only}';

    protected $description = 'Validate the catalogue files without touching the database. A file that checks clean imports clean.';

    public function handle(): int
    {
        Region::set($this->option('region'));
        $files = new CatalogueFiles(Region::current());
        $slug = $this->argument('brand');
        $r = (new CatalogueValidator($files))->check($slug ? [(string) $slug] : null);

        $issues = collect($r['issues'])->when(! $this->option('warnings'), fn ($c) => $c->where('level', 'error'));
        if ($this->option('summary')) {
            foreach ($issues->groupBy('file') as $file => $rows) {
                $this->line(sprintf('%-40s %d error(s), %d warning(s)', $file, $rows->where('level', 'error')->count(), $rows->where('level', 'warning')->count()));
            }
        } else {
            foreach ($issues as $i) {
                $line = strtoupper($i['level']).'  '.$i['file'].($i['where'] ? '  ['.$i['where'].']  ' : '  ').$i['message'];
                $i['level'] === 'error' ? $this->error($line) : $this->warn($line);
            }
        }
        $this->line("{$r['errors']} error(s), {$r['warnings']} warning(s)");

        return $r['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
