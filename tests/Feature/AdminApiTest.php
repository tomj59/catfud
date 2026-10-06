<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BrandNode;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private const A = '038100140173';

    private function as(User $u): void
    {
        Sanctum::actingAs($u);
    }

    private function ladder(): BrandNode
    {
        return BrandNode::ensurePath([['name' => 'Purina', 'kind' => 'manufacturer'], ['name' => 'Pro Plan', 'kind' => 'brand']]);
    }

    private function contribute(User $u, array $over = []): int
    {
        $this->as($u);
        $this->postJson('/api/v1/products', ['gtin' => self::A, 'name' => 'Chicken Entree', 'path' => 'Purina > Pro Plan', ...$over])->assertCreated();

        return Product::withoutGlobalScopes()->latest('id')->value('id');
    }

    public function test_regular_users_cannot_reach_any_admin_endpoint(): void
    {
        $this->as(User::factory()->create());
        foreach (['dashboard', 'products', 'requests', 'nodes', 'audit-log', 'products/export'] as $p) {
            $this->getJson("/api/v1/admin/{$p}")->assertForbidden();
        }
        $this->postJson('/api/v1/admin/products/1/moderate', ['action' => 'approve'])->assertForbidden();
    }

    public function test_moderators_see_everyones_pending_products_and_can_approve_one(): void
    {
        $this->ladder();
        $ann = User::factory()->create();
        $id = $this->contribute($ann);
        $mod = User::factory()->moderator()->create();
        $this->as($mod);

        $this->getJson('/api/v1/admin/products?moderation_status=pending')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.created_by', $ann->id);
        $this->postJson("/api/v1/admin/products/{$id}/moderate", ['action' => 'approve', 'note' => 'ok'])->assertOk()->assertJsonPath('product.moderation_status', 'approved');

        $this->as(User::factory()->create());
        $this->getJson('/api/v1/products/lookup/'.self::A)->assertOk();   // now public for everyone
        $this->assertDatabaseHas('audit_logs', ['subject_type' => 'Product', 'subject_id' => $id, 'action' => 'updated']);
    }

    public function test_sending_back_or_declining_needs_a_note_and_the_contributor_can_read_it(): void
    {
        $this->ladder();
        $ann = User::factory()->create();
        $id = $this->contribute($ann);
        $this->as(User::factory()->moderator()->create());

        $this->postJson("/api/v1/admin/products/{$id}/moderate", ['action' => 'reject'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/products/{$id}/moderate", ['action' => 'needs_changes', 'note' => 'Photo of the label please'])->assertOk();

        $this->as($ann);
        $this->getJson("/api/v1/products/{$id}")->assertOk()->assertJsonPath('product.moderation_status', 'needs_changes')->assertJsonPath('product.review_note', 'Photo of the label please');
    }

    public function test_an_unplaced_product_cannot_be_approved_until_it_has_a_ladder(): void
    {
        $ann = User::factory()->create();
        $id = $this->contribute($ann, ['path' => 'Purina > Pro Plan > Prime Plus']);   // nothing exists yet
        $this->as(User::factory()->moderator()->create());

        $this->postJson("/api/v1/admin/products/{$id}/moderate", ['action' => 'approve'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/products/{$id}/place", ['path' => 'Purina > Pro Plan > Prime Plus'])->assertOk()->assertJsonPath('product.placed', true);
        $this->postJson("/api/v1/admin/products/{$id}/moderate", ['action' => 'approve'])->assertOk();
        $this->assertNotNull(BrandNode::resolvePath(['Purina', 'Pro Plan', 'Prime Plus']));
    }

    public function test_the_request_queue_groups_by_typed_ladder_and_one_decision_settles_the_group(): void
    {
        $ann = User::factory()->create();
        $bob = User::factory()->create();
        $this->contribute($ann, ['path' => 'Purina > Pro Plan > Prime Plus']);
        $this->as($bob);
        $this->postJson('/api/v1/products', ['gtin' => '012345678905', 'name' => 'Turkey', 'path' => 'purina > pro plan > prime plus'])->assertCreated();
        $this->contribute($ann, ['gtin' => '4006381333931', 'name' => 'Odd One', 'path' => 'Zebra > Stripes']);

        $this->as(User::factory()->moderator()->create());
        $groups = $this->getJson('/api/v1/admin/requests')->assertOk()->json('groups');
        $this->assertCount(2, $groups);
        $this->assertSame(2, $groups[0]['products']);          // the one two people asked for comes first
        $this->assertSame(2, $groups[0]['contributors']);

        $this->postJson('/api/v1/admin/requests/resolve', ['requested_path' => $groups[0]['requested_path'], 'path' => 'Purina > Pro Plan > Prime Plus', 'approve' => true])
            ->assertOk()->assertJsonPath('placed', 2)->assertJsonPath('approved', 2);
        $this->postJson('/api/v1/admin/requests/decline', ['requested_path' => 'Zebra > Stripes', 'note' => 'Not a pet food brand'])->assertOk()->assertJsonPath('declined', 1);
        $this->getJson('/api/v1/admin/requests')->assertOk()->assertJsonCount(0, 'groups');
    }

    public function test_approving_a_second_copy_of_a_public_barcode_is_refused_and_merging_moves_the_users_stock(): void
    {
        $node = $this->ladder();
        $mod = User::factory()->moderator()->create();
        $ann = User::factory()->create();
        $dupe = $this->contribute($ann, ['name' => 'Chicken entree (my copy)']);       // Ann scanned it first; it is private to her
        $this->postJson('/api/v1/inventory', ['product_id' => $dupe, 'quantity' => 3])->assertCreated();

        $this->as($mod);                                                               // then staff add the real one
        $this->postJson('/api/v1/products', ['gtin' => self::A, 'name' => 'Chicken Entree', 'path' => 'Purina > Pro Plan'])->assertCreated();
        $public = Product::withoutGlobalScopes()->where('moderation_status', 'approved')->value('id');

        $this->as($mod);
        $this->postJson("/api/v1/admin/products/{$dupe}/moderate", ['action' => 'approve'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/products/{$dupe}/merge", ['into' => $public])->assertOk()->assertJsonPath('product.id', $public);

        $this->assertDatabaseHas('inventory_items', ['user_id' => $ann->id, 'product_id' => $public]);
        $this->assertDatabaseMissing('inventory_items', ['product_id' => $dupe]);
        $this->assertSame('merged', Product::withoutGlobalScopes()->find($dupe)->moderation_status);
        $this->assertSame($public, Product::withoutGlobalScopes()->find($dupe)->merged_into_id);
        $this->assertTrue(AuditLog::where('action', 'merged')->exists());
    }

    public function test_bulk_moderation_reports_each_product_separately(): void
    {
        $this->ladder();
        $ann = User::factory()->create();
        $ok = $this->contribute($ann);
        $unplaced = $this->contribute($ann, ['gtin' => '4006381333931', 'name' => 'Other', 'path' => 'Nowhere > Nothing']);
        $this->as(User::factory()->moderator()->create());

        $r = $this->postJson('/api/v1/admin/products/bulk', ['ids' => [$ok, $unplaced], 'action' => 'approve'])->assertOk();
        $this->assertSame([$ok], $r->json('done'));
        $this->assertSame($unplaced, $r->json('failed.0.id'));
    }

    public function test_export_streams_csv_and_dashboard_counts_the_work(): void
    {
        $this->ladder();
        $this->contribute(User::factory()->create(), ['path' => 'Nobody > Home']);
        BrandNode::ensurePath([['name' => 'Pro Plan Lines'], ['name' => 'Propplan']]);
        $this->as(User::factory()->moderator()->create());

        $csv = $this->get('/api/v1/admin/products/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Chicken Entree', $csv);

        $d = $this->getJson('/api/v1/admin/dashboard')->assertOk();
        $d->assertJsonPath('moderation.pending', 1)->assertJsonPath('moderation.pending_unplaced', 1);
        $this->assertGreaterThan(0, $d->json('tree.empty_nodes'));
    }

    public function test_only_admins_retire_nodes_and_a_preview_changes_nothing(): void
    {
        $node = $this->ladder();
        $ann = User::factory()->create();
        $id = $this->contribute($ann);
        $this->as($ann);
        $this->postJson('/api/v1/inventory', ['product_id' => $id, 'quantity' => 1])->assertCreated();

        $this->as(User::factory()->moderator()->create());
        $this->postJson("/api/v1/admin/nodes/{$node->id}/retire", ['status' => 'retired'])->assertForbidden();

        $this->as(User::factory()->admin()->create());
        $this->postJson("/api/v1/admin/nodes/{$node->id}/retire", ['status' => 'retired', 'preview' => true])->assertOk()
            ->assertJsonPath('applied', false)->assertJsonPath('impact.households', 1)->assertJsonPath('impact.pending_products', 1);
        $this->assertSame('active', $node->fresh()->status);

        $this->postJson("/api/v1/admin/nodes/{$node->id}/retire", ['status' => 'retired', 'status_on' => '2026-09-01', 'confidence' => 'confirmed', 'note' => 'Replaced'])
            ->assertOk()->assertJsonPath('node.status', 'retired')->assertJsonPath('node.status_on', '2026-09-01');
    }

    public function test_a_retired_line_is_not_offered_to_users_for_new_products_but_staff_still_see_it(): void
    {
        $this->ladder();
        $child = BrandNode::ensurePath([['name' => 'Purina'], ['name' => 'Pro Plan'], ['name' => 'Beyond']]);
        $parent = $child->parent;
        $parent->update(['status' => 'retired']);                // the brand retires, so its lines are effectively retired too
        $this->assertSame('retired', $child->fresh()->effectiveStatus());

        $this->as(User::factory()->create());
        $names = collect($this->getJson('/api/v1/brand-choices?path='.urlencode('Purina'))->assertOk()->json('choices'))->pluck('name');
        $this->assertNotContains('Pro Plan', $names->all());

        $this->as(User::factory()->moderator()->create());
        $row = collect($this->getJson('/api/v1/brand-choices?path='.urlencode('Purina'))->json('choices'))->firstWhere('name', 'Pro Plan');
        $this->assertSame('retired', $row['status']);
    }

    public function test_nodes_can_be_created_and_listed_and_only_empty_ones_deleted(): void
    {
        $node = $this->ladder();
        $this->as(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/nodes', ['parent_id' => $node->id, 'name' => 'Prime Plus', 'kind' => 'line'])->assertCreated()->assertJsonPath('created', true);
        $this->postJson('/api/v1/admin/nodes', ['parent_id' => $node->id, 'name' => 'prime plus'])->assertOk()->assertJsonPath('created', false);
        $rows = collect($this->getJson('/api/v1/admin/nodes?empty=1')->assertOk()->json('nodes'))->pluck('name');
        $this->assertContains('Prime Plus', $rows->all());

        $this->deleteJson('/api/v1/admin/nodes/'.$node->id)->assertUnprocessable();            // has a child
        $prime = BrandNode::where('name', 'Prime Plus')->value('id');
        $this->deleteJson("/api/v1/admin/nodes/{$prime}")->assertOk();
    }

    public function test_the_audit_log_can_be_filtered_to_one_product(): void
    {
        $this->ladder();
        $id = $this->contribute(User::factory()->create());
        $this->as(User::factory()->moderator()->create());
        $this->postJson("/api/v1/admin/products/{$id}/moderate", ['action' => 'approve'])->assertOk();

        $r = $this->getJson("/api/v1/admin/audit-log?subject_type=Product&subject_id={$id}")->assertOk();
        $this->assertGreaterThanOrEqual(2, count($r->json('data')));
        $this->assertSame('Product', $r->json('data.0.subject_type'));
    }

    public function test_the_ladder_lists_in_tree_order_so_sublines_stay_under_their_own_line(): void
    {
        BrandNode::ensurePath([['name' => "Hill's"], ['name' => 'Science Diet'], ['name' => 'Adult'], ['name' => 'Indoor']]);
        BrandNode::ensurePath([['name' => "Hill's"], ['name' => 'Science Diet'], ['name' => 'Adult 7+']]);   // sorts between "Adult" and "Adult>Indoor" by path
        BrandNode::ensurePath([['name' => "Hill's"], ['name' => 'Science Diet'], ['name' => 'Kitten']]);
        $this->as(User::factory()->moderator()->create());

        $names = collect($this->getJson('/api/v1/admin/nodes')->assertOk()->json('nodes'))->pluck('name')->all();
        $this->assertSame(["Hill's", 'Science Diet', 'Adult', 'Indoor', 'Adult 7+', 'Kitten'], $names);
    }

    public function test_each_line_reports_the_life_stages_actually_seen_and_products_can_be_browsed_by_them(): void
    {
        $this->as(User::factory()->admin()->create());
        $adult = BrandNode::ensurePath([['name' => 'Hills'], ['name' => 'Science Diet'], ['name' => 'Adult']]);
        $kitten = BrandNode::ensurePath([['name' => 'Hills'], ['name' => 'Science Diet'], ['name' => 'Kitten']]);
        $mapper = app(\App\Support\BrandTreeMapper::class);
        foreach ([[$adult, 'Chicken 7+', ['life_stage:adult-7plus']], [$adult, 'Beef', ['life_stage:adult']], [$adult, 'Turkey 7+', ['life_stage:adult-7plus']], [$kitten, 'Tuna', ['life_stage:kitten']]] as $i => [$node, $name, $tags]) {
            $p = Product::create(['brand' => 'Hills', 'name' => $name, 'species' => 'cat', 'kind' => 'food', 'source' => 't', 'moderation_status' => 'approved', 'gtin' => null]);
            $mapper->place($p, $node, $tags);
        }

        $rows = collect($this->getJson('/api/v1/admin/nodes')->assertOk()->json('nodes'));
        $line = $rows->firstWhere('name', 'Adult');
        $this->assertEqualsCanonicalizing(['adult-7plus' => 2, 'adult' => 1], collect($line['stages'])->pluck('count', 'slug')->all());
        $this->assertSame(['kitten' => 1], collect($rows->firstWhere('name', 'Kitten')['stages'])->pluck('count', 'slug')->all());
        $this->assertSame(3, collect($rows->firstWhere('name', 'Science Diet')['stages'])->count());   // rolls up: adult, adult-7plus, kitten

        $this->getJson('/api/v1/admin/products?node='.$adult->id.'&tag=life_stage:adult-7plus')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/admin/products')->assertOk()->assertJsonPath('meta.total', 4);          // no filter: browse everything
    }

    public function test_a_disabled_rung_is_hidden_from_users_like_a_retired_one_and_a_retiring_one_is_still_offered(): void
    {
        BrandNode::ensurePath([['name' => 'Acme'], ['name' => 'Old Line']]);
        BrandNode::ensurePath([['name' => 'Acme'], ['name' => 'Fading Line']]);
        BrandNode::ensurePath([['name' => 'Acme'], ['name' => 'Bad Data']]);
        $admin = User::factory()->admin()->create();
        $this->as($admin);
        foreach (['Old Line' => 'retired', 'Fading Line' => 'retiring', 'Bad Data' => 'disabled'] as $name => $status) {
            $id = BrandNode::where('name', $name)->value('id');
            $this->postJson("/api/v1/admin/nodes/{$id}/retire", ['status' => $status, 'status_on' => '2026-09-01'])->assertOk()->assertJsonPath('node.status', $status)->assertJsonPath('node.status_on', '2026-09-01');
        }
        $this->postJson('/api/v1/admin/nodes/'.BrandNode::where('name', 'Bad Data')->value('id').'/retire', ['status' => 'gone'])->assertUnprocessable();

        $this->as(User::factory()->create());
        $seen = collect($this->getJson('/api/v1/brand-choices?path=Acme')->assertOk()->json('choices'))->pluck('status', 'name')->all();
        $this->assertSame(['Fading Line' => 'retiring'], $seen);
    }

    public function test_a_status_change_remembers_the_previous_status_and_can_be_filtered_by_history(): void
    {
        BrandNode::ensurePath([['name' => 'Acme'], ['name' => 'Gone Line']]);
        BrandNode::ensurePath([['name' => 'Acme'], ['name' => 'Never Line']]);
        $this->as(User::factory()->admin()->create());
        $id = BrandNode::where('name', 'Gone Line')->value('id');

        $this->postJson("/api/v1/admin/nodes/{$id}/retire", ['status' => 'retired'])->assertOk()->assertJsonPath('node.previous_status', 'active');
        $this->postJson("/api/v1/admin/nodes/{$id}/retire", ['status' => 'disabled', 'note' => 'listed in error'])->assertOk()
            ->assertJsonPath('node.status', 'disabled')->assertJsonPath('node.previous_status', 'retired');

        $names = fn (string $qs) => collect($this->getJson("/api/v1/admin/nodes?{$qs}")->assertOk()->json('nodes'))->pluck('name')->all();
        $this->assertSame(['Gone Line'], $names('ever=retired'));
        $this->assertSame([], $names('status=retired'));
        $this->assertSame(['Gone Line'], $names('status=disabled'));
        $this->getJson('/api/v1/admin/nodes?ever=bogus')->assertUnprocessable();
    }
}
