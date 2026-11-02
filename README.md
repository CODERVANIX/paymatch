# PayMatch

PayMatch is an open source, self-hosted payment verification workflow for sellers who receive manual bank transfers. It records payable orders, imports bank statement CSVs, finds exact candidate matches, flags duplicates and unmatched credits, and records who authorized dispatch.

**A screenshot is never payment evidence in PayMatch.** The app has no screenshot verification flow. An imported CSV can be changed by whoever holds the file, so even a candidate match does not confirm receipt. An authorized approver must check the transaction against the bank's official record before releasing dispatch. The software records that decision; it does not independently authenticate a bank export.

## Quick start with Docker

1. Copy `.env.example` to `.env`.
2. Set a random `SECRET_KEY` and a unique admin password of at least 12 characters. For example, generate a secret with `python -c "import secrets; print(secrets.token_urlsafe(48))"`.
3. Run `docker compose up --build -d`.
4. Open <http://localhost:8000> and sign in with `ADMIN_EMAIL` and `ADMIN_PASSWORD`.

The Compose example binds to `127.0.0.1` so it is only reachable on the same machine. To serve a team, place it behind HTTPS and an authenticated reverse proxy, set `COOKIE_SECURE=true`, and configure backups for the `paymatch_data` Docker volume. Never put `.env` or bank CSVs in Git.

## Local development

Create a Python 3.12 virtual environment, install `requirements.txt`, set `SECRET_KEY`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`, and optionally `PAYMATCH_DB`, then run `python app.py`. The local development server listens on `127.0.0.1:8000`.

## CSV format

PayMatch expects UTF-8 CSV with these headers, in any order:

```csv
date,transaction_id,reference,amount,type
2026-11-02,BANK-4821,Transfer for PM-1001,25000.00,credit
2026-11-02,BANK-4822,Outgoing payment,1500.00,debit
```

Only `credit` rows are imported. Amounts are positive LKR values with up to two decimal places. Dates use `YYYY-MM-DD`. The importer accepts up to 5,000 rows and 2 MB. The account label is chosen by the importer and forms part of duplicate detection. Reimporting the same file for that account is rejected. Repeated transaction IDs on that account are flagged.

Bank formats vary. Convert your bank's *officially obtained* export into this format offline, preserving its transaction ID, date, amount and transfer reference. The CSV and account label are supplied by a user; PayMatch cannot prove they came from a bank. There is no bank-login scraping and the app never asks for customer banking credentials.

## Workflow

1. An **operator** creates an order with a unique code and expected LKR amount. The customer includes that code in the transfer reference.
2. An operator imports an official bank CSV. PayMatch links a transaction only when the amount exactly equals the order amount and the order code appears as a whole token in the reference. A candidate is created only if the match is unique. All other credits remain unmatched or flagged as duplicates.
3. An **approver** checks the candidate in the bank's official record and records a note and explicit dispatch decision. Only then is the order marked **Released**. Admins can perform both roles.
4. The audit screen records imports, matches, releases, sign-ins, and team changes, with actor and UTC time.

The audit log is application level and is not tamper proof against a database administrator. The current MVP has no refund, partial-payment, multi-currency, bank API, or payment-provider integration. It is intended for one organization per deployment. Do not use `Released` as an accounting settlement status.

## Security and operations

- Passwords use salted `scrypt` hashes. Sessions are signed, HTTP-only, and SameSite Lax; modifying forms require CSRF tokens.
- Keep the service behind HTTPS for network access. Set `COOKIE_SECURE=true` only when clients connect through HTTPS.
- Restrict who can import bank data or approve dispatch. Give each team member a separate login.
- Back up the persistent SQLite volume. SQLite WAL mode is enabled; copy a consistent database snapshot rather than copying only the `.sqlite3` file while the app is live.
- This MVP has no password reset, login rate limiting, immutable audit store, or direct bank integration. Add these before exposing it to the public internet or using it for high-volume operations.

## Tests

Run `python -m unittest discover -s tests -v`. The tests exercise matching, ambiguous imports, duplicate IDs, permissions, and human release.

## Contributing

Issues and pull requests are welcome. Start with a reproducible case and avoid including real customer or bank data. Keep the rule that a screenshot cannot set payment or dispatch status. See [CONTRIBUTING.md](CONTRIBUTING.md).

Licensed under MIT. This is an early MVP; evaluate and adapt it for your own operations before relying on it.
