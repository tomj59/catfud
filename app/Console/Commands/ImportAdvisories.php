<?php

namespace App\Console\Commands;

use App\Support\AdvisoryImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('advisories:import {file : Path to a .json file} {--dry-run : Validate and report without writing}')]
#[Description('Import Public Advisories (attributed third-party information) from a JSON file')]
class ImportAdvisories extends Command
{
    public function handle(AdvisoryImporter $importer): int
    {
        $dry = (bool) $this->option('dry-run');

        try {
            $result = $importer->importFile((string) $this->argument('file'), $dry);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dry ? '[dry run] ' : '')."advisories {$result['advisories']}, matches {$result['matches']}, errors ".count($result['errors']));

        foreach ($result['warnings'] as $w) {
            $this->warn($w);
        }
        foreach ($result['errors'] as $err) {
            $this->warn("entry {$err['entry']}: ".implode('; ', $err['messages']));
        }

        return $result['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
