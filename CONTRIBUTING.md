# Contributing to PayMatch

Thanks for helping. Open an issue describing the workflow and data format before adding a bank-specific parser or integration. Use synthetic transactions in tests and screenshots. Never commit actual bank exports, customer information, credentials, or `.env` files.

## Development checks

```sh
composer install
php artisan test
```

Changes to reconciliation should include a test for the state transition and its audit event. Dispatch must continue to require an authorized human decision backed by an official bank record. Keep CSV import distinct from bank authentication.

## Good first contributions

- A documented adapter for a bank's official CSV export
- Better exception review and manual investigation tools
- Password change and recovery flow
- Pagination and filters for larger histories
- Accessible mobile table layouts

By contributing, you agree to license your contribution under the MIT license.
