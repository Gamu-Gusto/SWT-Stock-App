<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthAndPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installReferenceData();
    }

    public function test_user_can_log_in_and_log_out(): void
    {
        $user = $this->makeUser(Role::MANAGER, ['email' => 'manager@swt.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'manager@swt.test', 'password' => 'Password123'])
            ->assertOk()->assertJsonPath('user.role.name', Role::MANAGER);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'user_id' => $user->id]);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_wrong_password_is_rejected_and_logins_are_rate_limited(): void
    {
        RateLimiter::clear('x');
        $this->makeUser(Role::SALES, ['email' => 'sales@swt.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'sales@swt.test', 'password' => 'wrong-password'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'sales@swt.test', 'password' => 'Password123'])->assertStatus(429);
    }

    public function test_deactivated_user_cannot_log_in_or_keep_using_the_api(): void
    {
        $user = $this->makeUser(Role::SALES, ['email' => 'gone@swt.test', 'is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => 'gone@swt.test', 'password' => 'Password123'])->assertStatus(422);

        $this->actingAs($user)->getJson('/api/v1/products')->assertStatus(401);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/products')->assertStatus(401);
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
        $this->postJson('/api/v1/sales', [])->assertStatus(401);
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'x@y.z'])->assertStatus(404);
        $this->postJson('/api/v1/users', ['name' => 'X'])->assertStatus(401);
    }

    public function test_temporary_password_must_be_changed_before_using_the_app(): void
    {
        $owner = $this->makeUser();
        $created = $this->actingAs($owner)->postJson('/api/v1/users', [
            'name' => 'New Clerk', 'email' => 'clerk@swt.test', 'role_id' => Role::query()->where('name', Role::CLERK)->value('id'),
        ])->assertCreated();
        $temporary = $created->json('temporary_password');
        $this->assertNotEmpty($temporary);

        auth()->guard('web')->logout();
        $this->app['auth']->forgetGuards(); // each real HTTP request starts with fresh guards
        $this->postJson('/api/v1/auth/login', ['email' => 'clerk@swt.test', 'password' => $temporary])->assertOk()->assertJsonPath('user.must_change_password', true);
        $this->getJson('/api/v1/products')->assertStatus(403)->assertJsonPath('code', 'password_change_required');

        $this->putJson('/api/v1/auth/password', ['current_password' => $temporary, 'password' => 'NewPassword2026', 'password_confirmation' => 'NewPassword2026'])->assertOk();
        $this->getJson('/api/v1/products')->assertOk();
    }

    public function test_role_permissions_are_enforced_on_the_server(): void
    {
        $product = $this->makeTyre(5);
        $sales = $this->makeUser(Role::SALES);
        $counter = $this->makeUser(Role::COUNTER);
        $clerk = $this->makeUser(Role::CLERK);

        $this->actingAs($sales)->postJson('/api/v1/receipts', [])->assertForbidden();
        $this->actingAs($sales)->getJson('/api/v1/movements')->assertForbidden();
        $this->actingAs($sales)->getJson('/api/v1/users')->assertForbidden();
        $this->actingAs($sales)->putJson('/api/v1/settings', ['business_name' => 'X'])->assertForbidden();
        $this->actingAs($counter)->getJson('/api/v1/products')->assertForbidden();
        $this->actingAs($counter)->postJson('/api/v1/sales', [])->assertForbidden();
        $this->actingAs($clerk)->postJson('/api/v1/sales', [])->assertForbidden();
        $this->actingAs($clerk)->getJson("/api/v1/products/{$product->id}")->assertOk();
    }

    public function test_supplier_costs_are_hidden_from_sales_employees(): void
    {
        $product = $this->makeTyre(5);

        $sales = $this->actingAs($this->makeUser(Role::SALES))->getJson("/api/v1/products/{$product->id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('cost_price', $sales);
        $this->assertArrayNotHasKey('cost_layers', $sales);
        $this->assertArrayNotHasKey('stock_value', $sales);
        $this->assertSame('1350.00', $sales['selling_price']);

        $owner = $this->actingAs($this->makeUser())->getJson("/api/v1/products/{$product->id}")->json('data');
        $this->assertSame('900.00', $owner['cost_price']);
    }

    public function test_only_owner_can_assign_owner_role_and_last_owner_is_protected(): void
    {
        $owner = $this->makeUser();
        $manager = $this->makeUser(Role::MANAGER);
        $manager->role->permissions()->syncWithoutDetaching(Permission::query()->where('name', 'users.manage')->pluck('id'));
        $ownerRole = Role::query()->where('name', Role::OWNER)->value('id');

        $this->actingAs($manager->fresh())->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@swt.test', 'role_id' => $ownerRole])->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/users/{$owner->id}/deactivate")->assertStatus(422);
    }

    public function test_owner_can_change_role_permissions_and_they_take_effect(): void
    {
        $owner = $this->makeUser();
        $manager = $this->makeUser(Role::MANAGER);
        $product = $this->makeTyre(5);
        $this->assertArrayNotHasKey('cost_price', $this->actingAs($manager)->getJson("/api/v1/products/{$product->id}")->json('data'));

        $role = Role::query()->where('name', Role::MANAGER)->first();
        $permissions = $role->permissions()->pluck('name')->push('financials.view')->all();
        $this->actingAs($owner)->putJson("/api/v1/roles/{$role->id}", ['permissions' => $permissions])->assertOk();

        $this->assertSame('900.00', $this->actingAs($manager->fresh())->getJson("/api/v1/products/{$product->id}")->json('data.cost_price'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'role.permissions_changed']);
    }

    public function test_reports_respect_permissions(): void
    {
        $this->makeTyre(5);
        $this->actingAs($this->makeUser(Role::SALES))->getJson('/api/v1/reports/current_stock')->assertForbidden();
        $this->actingAs($this->makeUser(Role::MANAGER))->getJson('/api/v1/reports/inventory_valuation')->assertForbidden();
        $this->actingAs($this->makeUser(Role::MANAGER))->getJson('/api/v1/reports/current_stock')->assertOk();

        $valuation = $this->actingAs($this->makeUser())->getJson('/api/v1/reports/inventory_valuation')->assertOk()->json('data');
        $this->assertSame('4500.00', $valuation['rows'][0]['cost_value']); // 5 × R900 (FIFO)
    }

    public function test_sensitive_values_are_never_written_to_the_audit_log(): void
    {
        $this->makeUser(Role::SALES, ['email' => 'audit@swt.test']);
        $this->postJson('/api/v1/auth/login', ['email' => 'audit@swt.test', 'password' => 'Password123'])->assertOk();

        $this->assertDatabaseMissing('activity_logs', ['new_values' => '%Password123%']);
        foreach (ActivityLog::all() as $log) {
            $this->assertStringNotContainsString('Password123', json_encode($log->toArray()));
        }
    }
}
