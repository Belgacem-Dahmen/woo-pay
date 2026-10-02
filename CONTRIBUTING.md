# Contributing to woo-pay

Thanks for helping! This plugin is intentionally simple and beginner-friendly.

## Quick start

1. Fork `StaveIndustries/woo-pay` and clone your fork.
2. Install dev deps:
   ```bash
   composer install
   ```
3. Run tests:
   ```bash
   composer test
   ```

## Code style

- PHP 7.4+, `declare(strict_types=1);` at the top of PHP files.
- Typed params/returns where possible.
- Comment for beginners: explain *why*, not just *what*.
- Keep WordPress-dependent code out of `includes/class-stellar-utils.php` so it stays unit-testable.
- JS: vanilla, no build step, keep `assets/checkout.js` small.

## Adding features

- New Horizon logic → put pure parts in `Stellar_Utils`, HTTP parts in `WC_Stellar_Checker`.
- New settings → add to `WC_Stellar_Settings::form_fields()` + `defaults()` + `.env.example` + README.
- New tests → add to `tests/StellarUtilsTest.php` (memo, address, matching).

## Pull requests

1. Create a branch: `git checkout -b feat/my-change`.
2. Add/adjust tests; ensure `vendor/bin/phpunit` passes.
3. Update README if behavior or settings change.
4. Keep PRs small and describe testnet steps to verify.

## Reporting bugs

Include: WP/WC versions, plugin version, network (testnet/public), asset, order memo (redact wallet if private), Horizon payment link if available.

## Security

Do not open PRs with secret keys. This plugin only needs public addresses. Report vulnerabilities via GitHub Security Advisories.
