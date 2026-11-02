<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Services\PaymentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function csv(string ...$rows): string
    {
        return "date,transaction_id,reference,amount,type\n".implode("\n", $rows)."\n";
    }

    public function test_only_a_unique_bank_record_candidate_can_be_released_by_an_approver(): void
    {
        $workflow = app(PaymentWorkflow::class);
        $operator = $this->actor('operator');
        $approver = $this->actor('approver');
        $order = $workflow->createOrder('PM-100', 'Customer', '200000.00', $operator);
        $this->assertSame('awaiting_transfer', $order->status);
        $workflow->importCsv($this->csv('2026-11-02,TX-1,PM-100,200000.00,credit'), 'statement.csv', 'Main', $operator);
        $this->assertSame('candidate', $order->fresh()->status);
        $this->assertDatabaseCount('bank_transactions', 1);
        $this->expectException(ValidationException::class);
        $workflow->release($order->id, 'Bank record checked', false, $approver);
    }

    public function test_release_records_the_person_and_audit_event(): void
    {
        $workflow = app(PaymentWorkflow::class);
        $operator = $this->actor('operator');
        $approver = $this->actor('approver');
        $order = $workflow->createOrder('PM-101', 'Customer', '500.00', $operator);
        $workflow->importCsv($this->csv('2026-11-02,TX-2,PM-101,500.00,credit'), 'statement.csv', 'Main', $operator);
        $released = $workflow->release($order->id, 'Checked in bank portal', true, $approver);
        $this->assertSame('released', $released->status);
        $this->assertSame($approver->id, $released->released_by);
        $this->assertNotNull($released->released_at);
        $this->assertTrue(AuditEvent::where('action', 'dispatch_released')->where('actor_id', $approver->id)->exists());
    }

    public function test_duplicate_bank_id_revokes_candidate_and_blocks_release(): void
    {
        $workflow = app(PaymentWorkflow::class);
        $operator = $this->actor('operator');
        $approver = $this->actor('approver');
        $order = $workflow->createOrder('PM-102', 'Customer', '500.00', $operator);
        $workflow->importCsv($this->csv('2026-11-02,TX-3,PM-102,500.00,credit'), 'first.csv', 'Main', $operator);
        $result = $workflow->importCsv($this->csv('2026-11-03,TX-3,PM-102,500.00,credit'), 'second.csv', 'Main', $operator);
        $this->assertSame(1, $result['duplicates']);
        $this->assertSame('awaiting_transfer', $order->fresh()->status);
        $this->assertNull($order->fresh()->matched_transaction_id);
        $this->assertSame(1, BankTransaction::whereNotNull('duplicate_of_id')->count());
        $this->expectException(ValidationException::class);
        $workflow->release($order->id, 'Checked in bank portal', true, $approver);
    }

    public function test_same_amount_without_order_code_does_not_match(): void
    {
        $workflow = app(PaymentWorkflow::class);
        $operator = $this->actor('operator');
        $order = $workflow->createOrder('PM-103', 'Customer', '500.00', $operator);
        $workflow->importCsv($this->csv('2026-11-02,TX-4,Unrelated,500.00,credit'), 'statement.csv', 'Main', $operator);
        $this->assertSame('awaiting_transfer', $order->fresh()->status);
    }

    public function test_operator_cannot_release_dispatch(): void
    {
        $workflow = app(PaymentWorkflow::class);
        $operator = $this->actor('operator');
        $order = $workflow->createOrder('PM-104', 'Customer', '500.00', $operator);
        $workflow->importCsv($this->csv('2026-11-02,TX-5,PM-104,500.00,credit'), 'statement.csv', 'Main', $operator);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $workflow->release($order->id, 'Checked in bank portal', true, $operator);
    }
}
