<?php

namespace App\Support;

use App\Enums\AuditStatus;
use App\Enums\ProductKind;
use App\Models\Product;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Imports product rows from a CSV or JSON file. Rows are upserted by canonical GTIN-13, so the same file can be
 * imported repeatedly, and a UPC-A or EAN-13 spelling of the same code lands on the same product.
 *
 * CSV columns: gtin, brand, name, species, kind, form, description, ingredients, image_url, source,
 * last_verified_at, plus any number of "nutrition_<label field>" columns (stored as printed on the label).
 * JSON: a list of objects with the same keys, where "nutrition" may be a nested object.
 */
class ProductImporter
{
    /**
     * @return array{created:int, updated:int, skipped:int, errors:array<int,array{row:int, gtin:?string, messages:list<string>}>}
     */
    public function importFile(string $path, bool $dryRun = false): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }

        $rows = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'csv' => $this->readCsv($path),
            'json' => $this->readJson($path),
            default => throw new InvalidArgumentException('Unsupported file type; use .csv or .json'),
        };

        return $this->import($rows, 'import:'.basename($path), $dryRun);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{created:int, updated:int, skipped:int, errors:array<int,array{row:int, gtin:?string, messages:list<string>}>}
     */
    public function import(array $rows, string $defaultSource, bool $dryRun = false): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 1;
            $data = $this->normaliseRow($row);

            $validator = Validator::make($data, [
                'brand' => ['required', 'string', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'species' => ['required', 'string', 'max:50'],
                'kind' => ['required', Rule::in(ProductKind::values())],
                'form' => ['nullable', 'string', 'max:100'],
                'image_url' => ['nullable', 'url', 'max:2048'],
                'last_verified_at' => ['nullable', 'date'],
                'import_key' => ['nullable', 'string', 'max:255'],
                'audit_status' => ['nullable', Rule::in(AuditStatus::values())],
                'meta' => ['nullable', 'array'],
            ]);

            // A row needs a valid GTIN, or (for seeded products with no barcode yet) a stable import_key.
            $gtin = Gtin::normalize($data['gtin'] ?? null);
            $importKey = $data['import_key'] ?? null;
            $messages = $validator->errors()->all();
            if (($data['gtin'] ?? null) !== null && $gtin === null) {
                $messages[] = 'gtin is not 8/12/13/14 digits, or has a bad check digit';
            } elseif ($gtin === null && $importKey === null) {
                $messages[] = 'row needs a gtin, or an import_key when the barcode is not known yet';
            }

            if ($messages) {
                $result['errors'][] = ['row' => $rowNumber, 'gtin' => $data['gtin'] ?? null, 'messages' => $messages];

                continue;
            }

            $attributes = [
                'brand' => $data['brand'],
                'name' => $data['name'],
                'species' => $data['species'],
                'kind' => $data['kind'],
                'form' => $data['form'] ?? null,
                'description' => $data['description'] ?? null,
                'ingredients' => $data['ingredients'] ?? null,
                'nutrition' => $data['nutrition'] ?: null,
                'image_url' => $data['image_url'] ?? null,
                'source' => $data['source'] ?? $defaultSource,
                'last_verified_at' => $data['last_verified_at'] ?? null,
                'import_key' => $importKey,
                'line' => $data['line'] ?? null,
                'texture' => $data['texture'] ?? null,
                'source_url' => $data['source_url'] ?? null,
                'meta' => $data['meta'] ?? null,
                'audit_notes' => $data['audit_notes'] ?? null,
            ];

            $existing = $gtin !== null ? Product::where('gtin', $gtin)->first() : null;
            $existing ??= $importKey !== null ? Product::where('import_key', $importKey)->first() : null;

            // Re-importing must never undo audit work: once a person has attached a barcode, edited, or reviewed a
            // seeded product, the file no longer owns it.
            if ($existing && $importKey !== null && $existing->import_key === $importKey && ! $existing->isUntouched()) {
                $result['skipped']++;

                continue;
            }

            if (! $dryRun) {
                if ($existing) {
                    // Never blank out a barcode the file does not know about.
                    $existing->update($gtin !== null ? [...$attributes, 'gtin' => $gtin] : $attributes);
                } else {
                    Product::create([
                        ...$attributes,
                        'gtin' => $gtin,
                        'audit_status' => $data['audit_status'] ?? AuditStatus::Unreviewed->value,
                    ]);
                }
            }

            $existing ? $result['updated']++ : $result['created']++;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (! $header) {
            fclose($handle);

            return [];
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]); // strip a UTF-8 BOM
        $header = array_map(fn ($h) => trim((string) $h), $header);

        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($line === [null] || count(array_filter($line, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue; // skip blank lines
            }
            $line = array_pad($line, count($header), null);
            $rows[] = array_combine($header, array_slice($line, 0, count($header)));
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $decoded['products'] ?? $decoded;

        return array_values(is_array($rows) ? $rows : []);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normaliseRow(array $row): array
    {
        $out = ['nutrition' => is_array($row['nutrition'] ?? null) ? $row['nutrition'] : []];

        foreach ($row as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;

            if (str_starts_with((string) $key, 'nutrition_')) {
                if ($value !== null && $value !== '') {
                    $out['nutrition'][substr((string) $key, 10)] = $value;
                }

                continue;
            }

            if ($key === 'nutrition') {
                continue;
            }

            $out[$key] = $value === '' ? null : $value;
        }

        $out['species'] = $out['species'] ?? 'cat';
        $out['kind'] = $out['kind'] ?? 'food';

        return $out;
    }
}
