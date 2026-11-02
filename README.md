# PayMatch 2

PayMatch is an open source, self-hosted bank transfer review workflow. Sellers create orders, import bank CSV transactions, review exact match candidates, and record who approved dispatch. Version 2 uses **Laravel 13 and Filament 5**, with a minimal operations UI.

**A screenshot can never verify a payment.** PayMatch has no screenshot verification flow. An imported CSV is user supplied and can be changed; a candidate match is not proof of receipt. An authorized approver must check the bank's official record before releasing dispatch. PayMatch records that human decision and does not authenticate a bank export itself.

## Docker quick start

1. Copy `.env.example` to `.env`.
2. Generate a key with `php artisan key:generate --show` if PHP and Composer are installed, or `openssl rand -base64 32` and prefix the result with `base64:`. Set `APP_KEY` in `.env`.
3. Run `docker compose up --build -d`.
4. Run `docker compose exec paymatch php artisan paymatch:create-admin` and enter the first administrator's name, email and password.
5. Open <http://localhost:8000/admin>.

The Compose example listens on `127.0.0.1`. For access from another machine, put PayMatch behind HTTPS, set `APP_URL` to that HTTPS URL, and arrange backups of the `paymatch_data` volume. Never commit `.env` or real bank CSVs.

## Local development

Requires PHP 8.3+, Composer, and the PHP `intl`, `pdo_sqlite`, and `zip` extensions. Run `composer install`, copy `.env.example` to `.env`, `php artisan key:generate`, create `database/database.sqlite`, then `php artisan migrate`. Create an administrator with `php artisan paymatch:create-admin` and run `php artisan serve`. Open <http://127.0.0.1:8000/admin>.

## CSV format

Use a UTF-8 CSV with these columns (in any order):

```csv
date,transaction_id,reference,amount,type
2026-11-02,BANK-4821,Transfer for PM-1001,25000.00,credit
2026-11-02,BANK-4822,Outgoing payment,1500.00,debit
```

Only credit rows are imported. Amounts are positive LKR values with up to two decimals. Dates use `YYYY-MM-DD`. A file can contain up to 5,000 rows and 2 MB. Reimporting the same file for the same account is rejected; repeated bank transaction IDs for that account are flagged as duplicates. Bank formats vary, so map an officially obtained export into this schema while preserving its transaction ID and reference. The app never asks for bank login or customer banking credentials.

## Workflow and permissions

1. An **operator** creates an order with a unique code and expected LKR amount. The payer includes the code in the transfer reference.
2. An operator imports a CSV. PayMatch proposes a candidate only when a credit has the exact amount and the order code as a whole token in its reference, with a unique match. Duplicates and ambiguous transfers remain unreleased.
3. An **approver** checks the transaction in the bank's official record, confirms that check, and writes an approval note. Only this action marks dispatch **Released**. An **admin** can perform both roles and manage staff.
4. Import, candidate, revocation, user creation, and release events appear in the audit history. The release record stores the approver, time, note, and linked transaction.

The audit log is application level and is not tamper proof against a database administrator. PayMatch is designed for one organization per deployment. It does not support partial payments, refunds, multiple currencies, direct bank APIs, or payment provider integrations. `Released` is a dispatch decision, not an accounting settlement status.

## Upgrade from v1

Version 2 is a Laravel rewrite. The v1 Flask source remains available at tag [`v1.0.0`](https://github.com/CODERVANIX/paymatch/tree/v1.0.0). **The v1 SQLite database and password hashes are not automatically migrated.** Back up your v1 database and deploy v2 with a new volume and administrator. Do not point v2 at a v1 database. If you need to carry production data forward, build and verify a controlled migration before switching dispatch operations.

## Security and tests

Laravel handles password hashing, sessions, and CSRF protection. Use a strong administrator password, individual staff accounts, HTTPS for network access, and database backups. This MVP has no bank integration or immutable audit store; evaluate it for your own operational risk.

Run `php artisan test` for workflow and access tests. Contributions are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md). Licensed under MIT.
