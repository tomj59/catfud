<?php

namespace App\Support\Catalogue;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where the catalogue lives on disk: database/catalogue/{region}/
 *
 *   vocabulary.json            the facet tags (texture, medium, life stage, diet) every product may carry
 *   ladders/{root}.json        one manufacturer (or stand-alone brand) and everything beneath it
 *   products/{brand}.json      the products of one brand, each with an explicit ladder path and explicit tags
 *
 * These files are the single source of truth for what the seed contains. Nothing is inferred from them at import time.
 */
final class CatalogueFiles
{
    public function __construct(public readonly string $region = 'US', private readonly ?string $baseDir = null) {}

    public function dir(): string
    {
        return $this->baseDir ?? database_path('catalogue/'.strtolower($this->region));
    }

    public function vocabularyPath(): string
    {
        return $this->dir().'/vocabulary.json';
    }

    public function ladderPath(string $slug): string
    {
        return $this->dir().'/ladders/'.$slug.'.json';
    }

    public function productsPath(string $slug): string
    {
        return $this->dir().'/products/'.$slug.'.json';
    }

    /** @return array<string,string> slug => path */
    public function ladders(): array
    {
        return $this->list($this->dir().'/ladders');
    }

    /** @return array<string,string> slug => path */
    public function products(): array
    {
        return $this->list($this->dir().'/products');
    }

    /** @return array<string,mixed> */
    public function read(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new RuntimeException('Not valid JSON: '.$path.' ('.json_last_error_msg().')');
        }

        return $data;
    }

    /** @param array<string,mixed> $data */
    public function write(string $path, array $data): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    public static function slug(string $name): string
    {
        return Str::slug(str_replace(["'", '’'], '', $name)) ?: 'unnamed';
    }

    /** @return array<string,string> */
    private function list(string $dir): array
    {
        $out = [];
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $out[basename($file, '.json')] = $file;
        }
        ksort($out);

        return $out;
    }
}
