<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_staff_are_sent_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_operator_can_open_import_but_approver_cannot(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $approver = User::factory()->create(['role' => 'approver']);

        $this->actingAs($operator)->get('/admin/import-statement')->assertOk();
        $this->actingAs($approver)->get('/admin/import-statement')->assertForbidden();
    }

    public function test_approver_cannot_create_orders_or_manage_users(): void
    {
        $approver = User::factory()->create(['role' => 'approver']);

        $this->actingAs($approver)->get('/admin/payment-orders/create')->assertForbidden();
        $this->actingAs($approver)->get('/admin/users')->assertForbidden();
    }
}
