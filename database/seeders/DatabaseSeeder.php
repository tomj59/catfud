<?php

namespace Database\Seeders;

use App\Models\Pet;
use App\Models\User;
use App\Support\AdvisoryImporter;
use App\Support\ProductImporter;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 20 clearly-labelled SAMPLE products (GTIN prefix 020 never collides with a real product).
        // Replace or extend with real products via: php artisan products:import path/to/file.csv
        app(ProductImporter::class)->importFile(database_path('seeds/products_sample.csv'));

        // Two clearly-labelled SAMPLE advisories, only to show how attribution and matching look.
        app(AdvisoryImporter::class)->importFile(database_path('seeds/advisories_sample.json'));

        // A throwaway pilot account for local testing only.
        if (app()->environment('local')) {
            // ~290 real cat-food products from the research spreadsheet. No barcodes yet: scan each package in the
            // app and pick the matching product to wire them up. Regenerate with scripts/xlsx_to_seed.py.
            if (is_file(database_path('seeds/catfood_seed.json'))) {
                app(ProductImporter::class)->importFile(database_path('seeds/catfood_seed.json'));
            }

            $user = User::firstOrCreate(
                ['email' => 'pilot@example.test'],
                ['name' => 'Pilot Tester', 'password' => 'password'],
            );
            Pet::firstOrCreate(['user_id' => $user->id, 'name' => 'Sample Cat'], ['species' => 'cat']);
        }
    }
}
