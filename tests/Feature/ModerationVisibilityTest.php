<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BrandNode;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contributed products are private to their contributor until a moderator approves them, and users never create
 * brand-tree nodes. These are the leak tests: someone else's pending item must not show up anywhere.
 */
class ModerationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const CODE_A = '038100140173';
    private const CODE_B = '012345678905';

    private function as(User $u): void
    {
        Sanctum::actingAs($u);
    }

    private function ladder(): BrandNode
    {
        return BrandNode::ensurePath([['name' => 'Purina', 'kind' => 'manufacturer'], ['name' => 'Pro Plan', 'kind' => 'brand', 'aliases' => ['Purina Pro Plan']]]);
    }

    private function contribute(User $u, array $over = []): \Illuminate\Testing\TestResponse
    {
        $this->as($u);

        return $this->postJson('/api/v1/products', ['gtin' => self::CODE_A, 'name' => 'Chicken Entree', 'path' => 'Purina > Pro Plan', 'form' => 'wet', ...$over]);
    }

    public function test_a_users_new_product_is_pending_and_private_but_fully_usable_by_them(): void
    {
        $this->ladder();
        $ann = User::factory()->create();
        $bob = User::factory()->create();

        $this->contribute($ann)->assertCreated()->assertJsonPath('product.moderation_status', 'pending')->assertJsonPath('product.placed', true);
        $id = Product::withoutGlobalScopes()->first()->id;

        $this->as($ann);
        $this->getJson('/api/v1/products/lookup/'.self::CODE_A)->assertOk();
        $this->getJson('/api/v1/products?q=chicken')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/products/{$id}")->assertOk();
        $this->postJson('/api/v1/inventory', ['product_id' => $id, 'quantity' => 2])->assertCreated();   // usable straight away

        $this->as($bob);
        $this->getJson('/api/v1/products/lookup/'.self::CODE_A)->assertNotFound();
        $this->getJson('/api/v1/products?q=chicken')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/products/{$id}")->assertNotFound();
        $this->getJson('/api/v1/products/audit-summary')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame([], $this->getJson('/api/v1/brand-nodes')->json('nodes')[0]['product_count'] ?? []);
    }

    public function test_approving_a_product_makes_it_public(): void
    {
        $this->ladder();
        $ann = User::factory()->create();
        $bob = User::factory()->create();
        $this->contribute($ann)->assertCreated();

        Product::withoutGlobalScopes()->first()->forceFill(['moderation_status' => 'approved', 'reviewed_by' => User::factory()->admin()->create()->id, 'reviewed_at' => now()])->save();

        $this->as($bob);
        $this->getJson('/api/v1/products/lookup/'.self::CODE_A)->assertOk()->assertJsonPath('product.name', 'Chicken Entree');
        $this->getJson('/api/v1/products?q=chicken')->assertJsonPath('meta.total', 1);
    }

    public function test_rejected_stays_private_to_its_contributor_and_merged_is_never_shown(): void
    {
        $this->ladder();
        $ann = User::factory()->create();
        $bob = User::factory()->create();
        $this->contribute($ann)->assertCreated();
        $p = Product::withoutGlobalScopes()->first();

        $p->forceFill(['moderation_status' => 'rejected', 'review_note' => 'Not pet food'])->save();
        $this->as($ann);
        $this->getJson("/api/v1/products/{$p->id}")->assertOk()->assertJsonPath('product.review_note', 'Not pet food');
        $this->as($bob);
        $this->getJson("/api/v1/products/{$p->id}")->assertNotFound();

        $p->update(['moderation_status' => 'merged']);
        $this->as($ann);
        $this->getJson("/api/v1/products/{$p->id}")->assertNotFound();
    }

    public function test_two_people_can_hold_the_same_unknown_barcode_privately(): void
    {
        $this->ladder();
        $ann = User::factory()->create();
        $bob = User::factory()->create();

        $this->contribute($ann)->assertCreated();
        $this->contribute($bob, ['name' => 'Chicken Dinner'])->assertCreated();       // no 409: Ann's copy is invisible to Bob
        $this->assertSame(2, Product::withoutGlobalScopes()->where('gtin', '0038100140173')->count());

        $this->as($bob);
        $this->getJson('/api/v1/products/lookup/'.self::CODE_A)->assertOk()->assertJsonPath('product.name', 'Chicken Dinner');
        $this->contribute($bob, ['name' => 'Again'])->assertStatus(409);               // but you cannot add your own twice
    }

    public function test_a_public_product_wins_over_a_private_copy_of_the_same_barcode(): void
    {
        $this->ladder();
        $ann = User::factory()->create();
        $this->contribute($ann)->assertCreated();
        Product::withoutGlobalScopes()->create(['gtin' => '0038100140173', 'brand' => 'Purina', 'name' => 'Public One', 'species' => 'cat', 'kind' => 'food',
            'moderation_status' => 'approved', 'source' => 'seed']);

        $this->as($ann);
        $this->getJson('/api/v1/products/lookup/'.self::CODE_A)->assertOk()->assertJsonPath('product.name', 'Public One');
    }

    public function test_a_users_ladder_must_already_exist_otherwise_the_product_is_unplaced_and_no_node_is_created(): void
    {
        $this->ladder();
        $ann = User::factory()->create();
        $nodes = BrandNode::count();

        $r = $this->contribute($ann, ['path' => 'Purina > Pro Plan > Prime Plus'])->assertCreated();
        $r->assertJsonPath('product.placed', false)->assertJsonPath('product.requested_path', 'Purina > Pro Plan > Prime Plus')
            ->assertJsonPath('product.path_text', null);
        $this->assertSame($nodes, BrandNode::count());                                // nothing created on a user's behalf
        $this->assertNull(Product::withoutGlobalScopes()->first()->brand_node_id);    // and no "nearest" rung either

        $this->as($ann);
        $this->getJson('/api/v1/products?q=prime')->assertJsonPath('meta.total', 1);          // the owner can still find it by what they typed
    }

    public function test_an_exact_alias_places_the_product_immediately(): void
    {
        $node = $this->ladder();
        $r = $this->contribute(User::factory()->create(), ['path' => 'purina > Purina Pro Plan'])->assertCreated();
        $r->assertJsonPath('product.placed', true)->assertJsonPath('product.path_text', 'Purina › Pro Plan');
        $this->assertSame($node->id, Product::withoutGlobalScopes()->first()->brand_node_id);
        $this->assertSame(2, BrandNode::count());
    }

    public function test_staff_add_to_the_catalogue_directly_and_may_create_nodes(): void
    {
        $r = $this->contribute(User::factory()->admin()->create(), ['path' => 'Zed > Line One'])->assertCreated();
        $r->assertJsonPath('product.moderation_status', 'approved')->assertJsonPath('product.placed', true);
        $this->assertNotNull(BrandNode::where('path_key', 'us|zed>line one')->first());
    }

    public function test_users_cannot_edit_the_public_catalogue_or_attach_barcodes_to_it(): void
    {
        $this->ladder();
        $pub = Product::create(['brand' => 'Purina', 'name' => 'Seed', 'species' => 'cat', 'kind' => 'food', 'source' => 'seed', 'import_key' => 'k1']);
        $this->as(User::factory()->create());

        $this->patchJson("/api/v1/products/{$pub->id}", ['name' => 'Defaced'])->assertForbidden();
        $this->putJson("/api/v1/products/{$pub->id}/barcode", ['gtin' => self::CODE_B])->assertForbidden();
        $this->assertSame('Seed', $pub->fresh()->name);

        $this->as(User::factory()->moderator()->create());
        $this->patchJson("/api/v1/products/{$pub->id}", ['name' => 'Fixed'])->assertOk();
    }

    public function test_a_contributor_can_edit_their_own_pending_product_but_not_staff_only_fields(): void
    {
        $this->ladder();
        $ann = User::factory()->create();
        $this->contribute($ann)->assertCreated();
        $id = Product::withoutGlobalScopes()->first()->id;

        $this->patchJson("/api/v1/products/{$id}", ['name' => 'Chicken Pate', 'audit_status' => 'reviewed', 'path' => 'Nope > Nothing'])->assertOk()
            ->assertJsonPath('product.name', 'Chicken Pate')->assertJsonPath('product.placed', false)->assertJsonPath('product.requested_path', 'Nope > Nothing');
        $this->assertSame('unreviewed', Product::withoutGlobalScopes()->first()->audit_status->value);     // the audit pass is staff-only
    }

    public function test_users_see_only_real_nodes_in_the_picker_staff_also_see_curated_names(): void
    {
        $this->ladder();
        $this->as(User::factory()->create());
        $names = array_column($this->getJson('/api/v1/brand-choices')->json('choices'), 'name');
        $this->assertSame(['Purina'], $names);                                         // not "Blue Buffalo" etc.: those are not nodes yet

        $this->as(User::factory()->admin()->create());
        $this->assertContains('Blue Buffalo', array_column($this->getJson('/api/v1/brand-choices')->json('choices'), 'name'));
    }

    public function test_only_admins_rename_move_or_merge_nodes(): void
    {
        $a = BrandNode::ensurePath([['name' => 'Acme']]);
        $b = BrandNode::ensurePath([['name' => 'Acmee']]);

        $this->as(User::factory()->create());
        $this->patchJson("/api/v1/brand-nodes/{$a->id}", ['name' => 'Acme Co'])->assertForbidden();
        $this->as(User::factory()->moderator()->create());
        $this->postJson("/api/v1/brand-nodes/{$b->id}/merge", ['into' => $a->id])->assertForbidden();
        $this->as(User::factory()->admin()->create());
        $this->postJson("/api/v1/brand-nodes/{$b->id}/merge", ['into' => $a->id])->assertOk();
    }

    public function test_roles_cannot_be_set_through_registration(): void
    {
        $this->postJson('/api/v1/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'password123', 'role' => 'admin'])->assertCreated();
        $this->assertSame('user', User::where('email', 'x@example.com')->first()->role);
    }

    public function test_changes_are_written_to_the_audit_log_with_who_and_what(): void
    {
        $this->ladder();
        $admin = User::factory()->admin()->create();
        $pub = Product::create(['brand' => 'Purina', 'name' => 'Seed', 'species' => 'cat', 'kind' => 'food', 'source' => 'seed', 'import_key' => 'k1']);

        $this->as($admin);
        $this->patchJson("/api/v1/products/{$pub->id}", ['name' => 'Fixed'])->assertOk();

        $log = AuditLog::where('subject_type', 'Product')->where('subject_id', $pub->id)->where('action', 'updated')->latest('id')->first();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(['Seed', 'Fixed'], $log->changes['name']);
        $this->assertNotContains('search_text', array_keys($log->changes));
    }

    public function test_the_role_command_sets_roles_and_logs_it(): void
    {
        $u = User::factory()->create(['email' => 'tom@example.com']);
        $this->artisan('user:role tom@example.com admin')->assertSuccessful();
        $this->assertTrue($u->fresh()->isAdmin());
        $this->artisan('user:role tom@example.com wizard')->assertFailed();
        $this->assertNotNull(AuditLog::where('action', 'role')->first());
    }
}
