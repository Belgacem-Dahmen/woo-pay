<?php
/**
 * Talks to the Stellar Horizon API to see if an order was paid.
 *
 * Beginners: Horizon is Stellar's free REST API. We ask:
 *   GET /accounts/{wallet}/payments?limit=50&order=desc
 * then look for a payment with matching memo + asset + amount.
 */

declare(strict_types=1);

if (!class_exists('Stellar_Utils')) {
    require_once __DIR__ . '/class-stellar-utils.php';
}

class WC_Stellar_Checker
{
    /**
     * Fetch recent payments for an address from Horizon.
     *
     * @param string $address Shop wallet address.
     * @param string $network 'testnet' or 'public'.
     * @param int $limit How many payments to fetch (default 50, max 200).
     * @return array<int,array<string,mixed>> List of payment records (may be empty).
     */
    public function fetch_payments(string $address, string $network = 'testnet', int $limit = 50): array
    {
        $base = Stellar_Utils::horizon_url($network);
        $url  = sprintf(
            '%s/accounts/%s/payments?limit=%d&order=desc',
            rtrim($base, '/'),
            rawurlencode($address),
            max(1, min(200, $limit))
        );

        // Use WordPress HTTP API when available, else plain file_get_contents (for tests/CLI).
        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get($url, ['timeout' => 15]);
            if (is_wp_error($res)) {
                return [];
            }
            $body = wp_remote_retrieve_body($res);
        } else {
            $ctx  = stream_context_create(['http' => ['timeout' => 15]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                return [];
            }
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return [];
        }

        // Horizon wraps results in _embedded.records, each needs its transaction memo.
        $records = $data['_embedded']['records'] ?? [];
        if (!is_array($records)) {
            return [];
        }

        // Enrich each payment with its transaction memo (one extra call per tx is slow,
        // so we fetch the transaction memo lazily only if "to" matches – done in check_order()).
        return array_values($records);
    }

    /**
     * Fetch a transaction's memo by its Horizon link.
     *
     * @param string $transaction_url Full Horizon URL for the transaction.
     * @return string Memo value or empty string.
     */
    public function fetch_transaction_memo(string $transaction_url): string
    {
        if ($transaction_url === '') {
            return '';
        }

        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get($transaction_url, ['timeout' => 15]);
            if (is_wp_error($res)) {
                return '';
            }
            $body = wp_remote_retrieve_body($res);
        } else {
            $ctx  = stream_context_create(['http' => ['timeout' => 15]]);
            $body = @file_get_contents($transaction_url, false, $ctx);
            if ($body === false) {
                return '';
            }
        }

        $tx = json_decode((string) $body, true);
        if (!is_array($tx)) {
            return '';
        }
        return (string) ($tx['memo'] ?? '');
    }

    /**
     * Check whether an order has been paid on Stellar.
     *
     * @param string $wallet_address Shop wallet to receive funds.
     * @param string $asset 'XLM' or 'USDC'.
     * @param string $expected_memo Unique memo assigned to this order.
     * @param float $expected_amount Order total.
     * @param string $network 'testnet' or 'public'.
     * @param array<int,array<string,mixed>>|null $prefetched Optional payments (for tests, skips HTTP).
     * @return array<string,mixed>|null Matching payment record, or null if not found.
     */
    public function find_matching_payment(
        string $wallet_address,
        string $asset,
        string $expected_memo,
        float $expected_amount,
        string $network = 'testnet',
        ?array $prefetched = null
    ): ?array {
        $payments = $prefetched ?? $this->fetch_payments($wallet_address, $network);

        foreach ($payments as $p) {
            // Skip non-payment types (e.g. create_account). We only want type=payment.
            if (isset($p['type']) && $p['type'] !== 'payment') {
                continue;
            }

            // Attach memo if Horizon didn't inline it.
            if (!isset($p['memo']) && isset($p['transaction']['href'])) {
                $p['memo'] = $this->fetch_transaction_memo((string) $p['transaction']['href']);
            } elseif (!isset($p['memo']) && isset($p['_links']['transaction']['href'])) {
                $p['memo'] = $this->fetch_transaction_memo((string) $p['_links']['transaction']['href']);
            }

            if (Stellar_Utils::payment_matches($p, $wallet_address, $asset, $expected_memo, $expected_amount)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Mark a WooCommerce order as paid (called when a matching payment is found).
     *
     * @param int $order_id WC order ID.
     * @param array<string,mixed> $payment Matching Horizon payment.
     * @return bool True on success.
     */
    public function mark_order_paid(int $order_id, array $payment): bool
    {
        if (!function_exists('wc_get_order')) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        // Don't double-pay already-completed orders.
        if ($order->is_paid()) {
            return true;
        }

        $tx_hash = (string) ($payment['transaction_hash'] ?? $payment['id'] ?? '');
        $order->payment_complete($tx_hash);
        /* translators: %s Stellar transaction hash */
        $order->add_order_note(sprintf(__('Stellar payment confirmed. TX: %s', 'woo-pay'), $tx_hash));
        $order->update_meta_data('_stellar_tx_hash', $tx_hash);
        $order->save();

        return true;
    }
}
