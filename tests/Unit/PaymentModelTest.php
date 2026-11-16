<?php

namespace Tests\Unit;

use App\Models\Payment;
use Carbon\Carbon;
use Tests\TestCase;

class PaymentModelTest extends TestCase
{
    public function test_is_overdue_returns_true_for_pending_payment_past_the_grace_period(): void
    {
        $payment = new Payment();
        $payment->status   = 'pending';
        $payment->due_date = Carbon::today()->subDays(8);

        $this->assertTrue($payment->isOverdue());
    }

    public function test_is_overdue_returns_false_within_the_default_grace_period(): void
    {
        $payment = new Payment();
        $payment->status   = 'pending';
        $payment->due_date = Carbon::today()->subDays(7);

        $this->assertFalse($payment->isOverdue());
    }

    public function test_is_overdue_returns_false_when_payment_is_paid(): void
    {
        $payment = new Payment();
        $payment->status   = 'paid';
        $payment->due_date = Carbon::yesterday();

        $this->assertFalse($payment->isOverdue());
    }

    public function test_is_overdue_returns_false_when_due_date_is_in_future(): void
    {
        $payment = new Payment();
        $payment->status   = 'pending';
        $payment->due_date = Carbon::tomorrow();

        $this->assertFalse($payment->isOverdue());
    }
}
