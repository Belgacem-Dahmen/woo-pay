<?php
/**
 * Pure-PHP Stellar helpers.
 *
 * Beginners: this file has NO WordPress code so PHPUnit can test it easily.
 * It handles: address validation, memo generation, payment matching.
 */

declare(strict_types=1);

class Stellar_Utils
{
    /**
     * Stellar public keys look like: G + 55 base32 chars (A-Z, 2-7), 56 total.
     * This is a format check (StrKey). Full checksum validation needs libsodium/crc16.
     */
    public const ADDRESS_REGEX = '/^G[A-Z2-7]{55}$/';

    /** Memo text max length on Stellar is 28 bytes. */
    public const MEMO_MAX_LENGTH = 28;

    /**
     * Validate a Stellar wallet address (StrKey format check).
     *
     * @param string $address Address to check.
     * @return bool True if it looks like G... 56 chars base32.
     */
    public static function is_valid_address(string $address): bool
    {
        $address = trim($address);
        if ($address === '') {
            return false;
        }
        // Must be exactly 56 chars, start with G.
        if (strlen($address) !== 56) {
            return false;
        }
        return (bool) preg_match(self::ADDRESS_REGEX, $address);
    }

    /**
     * Generate a unique memo per order for payment matching.
     *
     * Format: WOO-{orderId}-{6 random uppercase alphanumeric}
     * Example: WOO-123-AB12CD (always <= 28 chars).
     *
     * @param int $order_id WooCommerce order ID (must be > 0).
     * @return string Memo text to attach to the Stellar payment.
     */
    public static function generate_memo(int $order_id): string
    {
        if ($order_id <= 0) {
            throw new InvalidArgumentException('Order ID must be a positive integer.');
        }

        // 6 random chars from A-Z0-9 (Crockford-ish, no confusing chars needed but keep simple).
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $rand = '';
        for ($i = 0; $i < 6; $i++) {
            $rand .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $memo = sprintf('WOO-%d-%s', $order_id, $rand);

        // Safety: truncate if order ID is huge (memo limit 28).
        if (strlen($memo) > self::MEMO_MAX_LENGTH) {
            // Keep prefix + tail of order id so it still fits.
            $memo = substr($memo, 0, self::MEMO_MAX_LENGTH);
        }

        return $memo;
    }

    /**
     * Check if a memo belongs to an order (prefix match).
     * Useful when random suffix differs but order id matches.
     *
     * @param string $memo Memo from Horizon.
     * @param int $order_id Expected order ID.
     * @return bool
     */
    public static function memo_matches_order(string $memo, int $order_id): bool
    {
        $prefix = sprintf('WOO-%d-', $order_id);
        return strpos($memo, $prefix) === 0;
    }

    /**
     * Decide if a Horizon payment record pays for this order.
     *
     * Beginners: Horizon returns JSON like:
     *   { "to": "G...", "from": "G...", "amount": "10.5",
     *     "asset_type": "native" | "credit_alphanum4",
     *     "asset_code": "USDC", "transaction": { "memo": "WOO-123-..." } }
     *
     * We check: destination == our wallet, memo == expected, amount >= expected,
     * and asset matches (XLM = native, USDC = credit with code USDC).
     *
     * @param array<string,mixed> $payment One Horizon payment record (decoded JSON).
     * @param string $expected_address Our shop wallet address.
     * @param string $expected_asset 'XLM' or 'USDC' (case-insensitive).
     * @param string $expected_memo Exact memo we gave this order.
     * @param float $expected_amount Minimum amount (order total).
     * @return bool True if this payment satisfies the order.
     */
    public static function payment_matches(
        array $payment,
        string $expected_address,
        string $expected_asset,
        string $expected_memo,
        float $expected_amount
    ): bool {
        $to = (string) ($payment['to'] ?? '');
        if (strcasecmp($to, $expected_address) !== 0) {
            return false;
        }

        // Memo can be top-level or nested under transaction (Horizon embeds differently).
        $memo = '';
        if (isset($payment['memo'])) {
            $memo = (string) $payment['memo'];
        } elseif (isset($payment['transaction']['memo'])) {
            $memo = (string) $payment['transaction']['memo'];
        } elseif (isset($payment['transaction_attr']['memo'])) {
            $memo = (string) $payment['transaction_attr']['memo'];
        }

        if ($memo !== $expected_memo) {
            return false;
        }

        $amount = (float) ($payment['amount'] ?? 0);
        // Allow tiny float dust (0.0000001 = 1 stroop). Require amount >= expected - epsilon.
        if ($amount + 0.0000001 < $expected_amount) {
            return false;
        }

        $asset = strtoupper(trim($expected_asset));
        $type  = (string) ($payment['asset_type'] ?? '');
        $code  = strtoupper((string) ($payment['asset_code'] ?? ''));

        if ($asset === 'XLM') {
            // Native XLM shows as asset_type=native and no asset_code.
            return $type === 'native';
        }

        if ($asset === 'USDC') {
            // USDC is an issued asset: credit_alphanum4 with code USDC.
            // We accept any USDC issuer here; strict issuer check happens in settings/checker.
            return ($type === 'credit_alphanum4' || $type === 'credit_alphanum12') && $code === 'USDC';
        }

        return false;
    }

    /**
     * Return the Horizon base URL for a network.
     *
     * @param string $network 'testnet' or 'public' (mainnet).
     * @return string Base URL without trailing slash.
     */
    public static function horizon_url(string $network): string
    {
        if (strtolower($network) === 'public' || strtolower($network) === 'mainnet') {
            return 'https://horizon.stellar.org';
        }
        return 'https://horizon-testnet.stellar.org';
    }
}
