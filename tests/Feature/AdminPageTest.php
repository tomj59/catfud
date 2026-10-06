<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminPageTest extends TestCase
{
    public function test_the_admin_page_is_served_and_its_script_exists(): void
    {
        $this->get('/admin')->assertOk()->assertHeader('content-type', 'text/html; charset=UTF-8');
        $this->assertStringContainsString('/vendor/alpine.min.js', (string) file_get_contents(resource_path('admin/index.html')));
        $this->assertFileExists(public_path('vendor/alpine.min.js'));
    }
}
