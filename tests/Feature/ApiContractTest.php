<?php

namespace Tests\Feature;

use Dedoc\Scramble\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/openapi.json is the contract the admin tool and the mobile app build against. If the code changes the API,
 * regenerate it (`php artisan scramble:export --path=docs/openapi.json`) and commit the result in the same change.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_committed_openapi_spec_matches_the_code(): void
    {
        $generated = app(Generator::class)();
        $committed = json_decode((string) file_get_contents(base_path('docs/openapi.json')), true);

        // The server URL depends on APP_URL and is not part of the contract.
        unset($generated['servers'], $committed['servers']);

        $normalise = fn (array $a) => json_decode(json_encode($a), true);
        $this->assertEquals($normalise($committed), $normalise($generated), 'docs/openapi.json is out of date. Run: php artisan scramble:export --path=docs/openapi.json');
    }

    public function test_every_v1_route_is_documented_and_nothing_lives_outside_v1(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $this->assertStringStartsWith('api/v1/', $route->uri(), 'API routes must be versioned: '.$route->uri());
            }
        }
    }
}
