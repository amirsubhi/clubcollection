<?php

namespace Tests\Unit;

use App\Support\Payments\BillplzSignature;
use PHPUnit\Framework\TestCase;

class BillplzSignatureTest extends TestCase
{
    public function test_source_string_sorts_keys_alphabetically_and_excludes_signature(): void
    {
        $payload = [
            'id' => 'BILL-1',
            'paid' => 'true',
            'amount' => '5000',
            'x_signature' => 'should-be-stripped',
        ];

        $this->assertSame(
            'amount5000|idBILL-1|paidtrue',
            BillplzSignature::source($payload),
        );
    }

    public function test_compute_produces_stable_hmac_sha256(): void
    {
        $payload = ['id' => 'BILL-1', 'paid' => 'true'];
        $key = 'test-key';

        $expected = hash_hmac('sha256', 'idBILL-1|paidtrue', $key);
        $this->assertSame($expected, BillplzSignature::compute($payload, $key));
    }

    public function test_compute_changes_when_any_field_changes(): void
    {
        $a = BillplzSignature::compute(['id' => '1', 'paid' => 'true'], 'k');
        $b = BillplzSignature::compute(['id' => '1', 'paid' => 'false'], 'k');
        $this->assertNotSame($a, $b);
    }
}
