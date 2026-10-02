<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-stellar-utils.php';

/**
 * Beginners: run with `composer test` or `vendor/bin/phpunit`.
 * These cover the three required areas: memo generation, address validation, payment matching.
 */
final class StellarUtilsTest extends TestCase
{
    public function test_valid_addresses(): void
    {
        // Real-format examples (G + 55 base32 chars).
        $this->assertTrue(Stellar_Utils::is_valid_address('GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5'));
        $this->assertTrue(Stellar_Utils::is_valid_address('GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF'));
    }

    public function test_invalid_addresses(): void
    {
        $this->assertFalse(Stellar_Utils::is_valid_address(''));
        $this->assertFalse(Stellar_Utils::is_valid_address('short'));
        $this->assertFalse(Stellar_Utils::is_valid_address('S' . str_repeat('A', 55))); // secret key, not public
        $this->assertFalse(Stellar_Utils::is_valid_address('G' . str_repeat('0', 55))); // 0 not in base32 alphabet
        $this->assertFalse(Stellar_Utils::is_valid_address('G' . str_repeat('A', 54))); // too short (55 total)
        $this->assertFalse(Stellar_Utils::is_valid_address('G' . str_repeat('A', 56))); // too long (57 total)
        $this->assertFalse(Stellar_Utils::is_valid_address('g' . strtolower(str_repeat('A', 55)))); // lowercase
    }

    public function test_generate_memo_unique_and_valid(): void
    {
        $a = Stellar_Utils::generate_memo(123);
        $b = Stellar_Utils::generate_memo(123);

        // Format WOO-{id}-{6 chars}, max 28 chars (Stellar memo text limit).
        $this->assertMatchesRegularExpression('/^WOO-123-[A-Z2-9]{6}$/', $a);
        $this->assertLessThanOrEqual(28, strlen($a));

        // Two memos for same order should differ (random suffix) to avoid replays.
        $this->assertNotSame($a, $b);

        // Prefix helper links memo back to order.
        $this->assertTrue(Stellar_Utils::memo_matches_order($a, 123));
        $this->assertFalse(Stellar_Utils::memo_matches_order($a, 999));
    }

    public function test_generate_memo_rejects_bad_order_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Stellar_Utils::generate_memo(0);
    }

    public function test_payment_matches_xlm(): void
    {
        $wallet = 'GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5';
        $memo = 'WOO-7-ABC123';

        $good = [
            'to' => $wallet,
            'amount' => '25.0000000',
            'asset_type' => 'native',
            'memo' => $memo,
        ];
        $this->assertTrue(Stellar_Utils::payment_matches($good, $wallet, 'XLM', $memo, 25.0));

        // Underpaid → false.
        $under = $good;
        $under['amount'] = '24.99';
        $this->assertFalse(Stellar_Utils::payment_matches($under, $wallet, 'XLM', $memo, 25.0));

        // Wrong memo → false.
        $wrongMemo = $good;
        $wrongMemo['memo'] = 'WOO-7-XXXXXX';
        $this->assertFalse(Stellar_Utils::payment_matches($wrongMemo, $wallet, 'XLM', $memo, 25.0));

        // Wrong destination → false.
        $wrongTo = $good;
        $wrongTo['to'] = 'GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF';
        $this->assertFalse(Stellar_Utils::payment_matches($wrongTo, $wallet, 'XLM', $memo, 25.0));

        // Wrong asset (USDC payment when expecting XLM) → false.
        $wrongAsset = $good;
        $wrongAsset['asset_type'] = 'credit_alphanum4';
        $wrongAsset['asset_code'] = 'USDC';
        $this->assertFalse(Stellar_Utils::payment_matches($wrongAsset, $wallet, 'XLM', $memo, 25.0));
    }

    public function test_payment_matches_usdc_and_nested_memo(): void
    {
        $wallet = 'GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5';
        $memo = 'WOO-42-QWERTY';

        // Horizon often nests memo under transaction.
        $good = [
            'to' => $wallet,
            'amount' => '10.50',
            'asset_type' => 'credit_alphanum4',
            'asset_code' => 'USDC',
            'transaction' => ['memo' => $memo],
        ];
        $this->assertTrue(Stellar_Utils::payment_matches($good, $wallet, 'USDC', $memo, 10.50));
        // Case-insensitive asset name.
        $this->assertTrue(Stellar_Utils::payment_matches($good, $wallet, 'usdc', $memo, 10.50));

        // XLM is not USDC.
        $xlm = $good;
        $xlm['asset_type'] = 'native';
        unset($xlm['asset_code']);
        $this->assertFalse(Stellar_Utils::payment_matches($xlm, $wallet, 'USDC', $memo, 10.50));
    }

    public function test_horizon_url(): void
    {
        $this->assertSame('https://horizon-testnet.stellar.org', Stellar_Utils::horizon_url('testnet'));
        $this->assertSame('https://horizon.stellar.org', Stellar_Utils::horizon_url('public'));
        $this->assertSame('https://horizon.stellar.org', Stellar_Utils::horizon_url('mainnet'));
    }
}
