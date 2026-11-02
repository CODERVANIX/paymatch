<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\PaymentOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use League\Csv\Reader;

class PaymentWorkflow
{
    public function createOrder(string $code, string $customer, string $amount, User $actor): PaymentOrder
    {
        abort_unless(in_array($actor->role, ['admin', 'operator'], true), 403);
        $code = strtoupper(trim($code));
        $customer = trim($customer);
        if (! preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/', $code) || $customer === '' || mb_strlen($customer) > 120) {
            throw ValidationException::withMessages(['code' => 'Enter a valid order code and customer name.']);
        }
        $cents = self::cents($amount);

        return DB::transaction(function () use ($code, $customer, $cents, $actor) {
            $order = PaymentOrder::create([
                'code' => $code, 'customer' => $customer, 'amount_cents' => $cents,
                'status' => 'awaiting_transfer', 'created_by' => $actor->id,
            ]);
            AuditEvent::record($actor->id, 'order_created', 'order', $order->id, "{$code}; LKR ".number_format($cents / 100, 2));
            $this->reconcile();
            return $order->refresh();
        });
    }

    public function importCsv(string $bytes, string $fileName, string $accountLabel, User $actor): array
    {
        abort_unless(in_array($actor->role, ['admin', 'operator'], true), 403);
        $accountLabel = trim($accountLabel);
        if ($accountLabel === '' || mb_strlen($accountLabel) > 80 || strlen($bytes) > 2 * 1024 * 1024 || ! mb_check_encoding($bytes, 'UTF-8')) {
            throw ValidationException::withMessages(['statement' => 'Use a UTF-8 CSV under 2 MB and enter an account label.']);
        }
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        $reader = Reader::createFromString($bytes);
        $reader->setHeaderOffset(0);
        $headers = array_map(fn ($header) => strtolower(trim($header)), $reader->getHeader());
        $required = ['date', 'transaction_id', 'reference', 'amount', 'type'];
        if (count(array_intersect($required, $headers)) !== count($required) || count($headers) !== count(array_unique($headers))) {
            throw ValidationException::withMessages(['statement' => 'CSV needs unique date, transaction_id, reference, amount, type columns.']);
        }
        $rows = [];
        foreach ($reader->getRecords() as $index => $raw) {
            if ($index > 5000) {
                throw ValidationException::withMessages(['statement' => 'Maximum 5,000 rows per import.']);
            }
            $row = array_combine($headers, array_values($raw));
            if (strtolower(trim($row['type'])) !== 'credit') {
                continue;
            }
            $date = trim($row['date']);
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (! $parsedDate || $parsedDate->format('Y-m-d') !== $date) {
                throw ValidationException::withMessages(['statement' => "Invalid date on CSV row {$index}."]);
            }
            $bankId = trim($row['transaction_id']);
            $reference = trim($row['reference']);
            if ($bankId === '' || mb_strlen($bankId) > 120 || mb_strlen($reference) > 500) {
                throw ValidationException::withMessages(['statement' => "Invalid transaction ID or reference on CSV row {$index}."]);
            }
            $rows[] = [
                'account_label' => $accountLabel, 'booked_on' => $date,
                'bank_id' => $bankId, 'reference' => $reference,
                'amount_cents' => self::cents($row['amount']),
                'fingerprint' => hash('sha256', $accountLabel."\0".$bankId),
            ];
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['statement' => 'No credit transactions found.']);
        }

        return DB::transaction(function () use ($bytes, $fileName, $accountLabel, $actor, $rows) {
            $sha = hash('sha256', $accountLabel."\0".$bytes);
            if (BankImport::where('file_sha256', $sha)->exists()) {
                throw ValidationException::withMessages(['statement' => 'This file was already imported for that account.']);
            }
            $import = BankImport::create([
                'file_name' => mb_substr($fileName, 0, 200), 'account_label' => $accountLabel,
                'file_sha256' => $sha, 'row_count' => count($rows), 'imported_by' => $actor->id,
            ]);
            $duplicates = 0;
            foreach ($rows as $row) {
                $priorId = BankTransaction::where('fingerprint', $row['fingerprint'])->orderBy('id')->value('id');
                $row['bank_import_id'] = $import->id;
                $row['duplicate_of_id'] = $priorId;
                BankTransaction::create($row);
                if ($priorId) {
                    $duplicates++;
                }
            }
            AuditEvent::record($actor->id, 'csv_imported', 'import', $import->id,
                count($rows)." credits; {$duplicates} duplicate bank IDs; account {$accountLabel}");
            $this->reconcile();
            return ['import' => $import, 'credits' => count($rows), 'duplicates' => $duplicates];
        });
    }

    public function release(int $orderId, string $note, bool $bankRecordChecked, User $actor): PaymentOrder
    {
        abort_unless(in_array($actor->role, ['admin', 'approver'], true), 403);
        $note = trim($note);
        if (! $bankRecordChecked || mb_strlen($note) < 8 || mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => 'Check the official bank record and enter an approval note of 8–500 characters.']);
        }

        return DB::transaction(function () use ($orderId, $note, $actor) {
            $this->reconcile();
            $order = PaymentOrder::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->status !== 'candidate' || ! $order->matched_transaction_id) {
                throw ValidationException::withMessages(['order' => 'This order has no unique candidate match.']);
            }
            $order->update(['status' => 'released', 'released_by' => $actor->id,
                'released_at' => now(), 'release_note' => $note]);
            AuditEvent::record($actor->id, 'dispatch_released', 'order', $order->id, $note);
            return $order->refresh();
        });
    }

    public function reconcile(): void
    {
        $orders = PaymentOrder::where('status', '!=', 'released')->orderBy('id')->get();
        $releasedTxIds = PaymentOrder::where('status', 'released')->whereNotNull('matched_transaction_id')->pluck('matched_transaction_id')->all();
        $duplicateOriginalIds = BankTransaction::whereNotNull('duplicate_of_id')->pluck('duplicate_of_id')->all();
        $transactions = BankTransaction::whereNull('duplicate_of_id')
            ->whereNotIn('id', array_merge($releasedTxIds, $duplicateOriginalIds))->get()->groupBy('amount_cents');
        $candidates = [];
        $claimants = [];
        foreach ($orders as $order) {
            $matches = $transactions->get($order->amount_cents, collect())
                ->filter(fn ($tx) => self::hasCode($tx->reference, $order->code));
            if ($matches->count() === 1) {
                $txId = $matches->first()->id;
                $candidates[$order->id] = $txId;
                $claimants[$txId] = ($claimants[$txId] ?? 0) + 1;
            }
        }
        // Clear stale links before assigning new ones; each transaction belongs
        // to at most one order.
        foreach ($orders as $order) {
            $desired = $candidates[$order->id] ?? null;
            if ($desired && $claimants[$desired] !== 1) {
                $desired = null;
            }
            if ($order->matched_transaction_id && $order->matched_transaction_id !== $desired) {
                BankTransaction::whereKey($order->matched_transaction_id)->update(['matched_order_id' => null]);
                $order->update(['matched_transaction_id' => null, 'status' => 'awaiting_transfer']);
                AuditEvent::record(null, 'candidate_revoked', 'order', $order->id, 'New records made the match ambiguous or duplicate');
            }
        }
        foreach ($orders as $order) {
            $desired = $candidates[$order->id] ?? null;
            if ($desired && $claimants[$desired] !== 1) {
                $desired = null;
            }
            if ($order->matched_transaction_id === $desired) {
                continue;
            }
            $order->update(['status' => $desired ? 'candidate' : 'awaiting_transfer', 'matched_transaction_id' => $desired]);
            if ($desired) {
                BankTransaction::whereKey($desired)->update(['matched_order_id' => $order->id]);
                AuditEvent::record(null, 'candidate_match', 'order', $order->id, "Transaction {$desired} matched exact code and amount; approval required");
            }
        }
    }

    public static function cents(string $amount): int
    {
        $amount = str_replace(',', '', trim($amount));
        if (! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive amount with at most two decimals.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be positive.']);
        }
        return $cents;
    }

    private static function hasCode(string $reference, string $code): bool
    {
        return (bool) preg_match('/(?<![A-Z0-9_-])'.preg_quote($code, '/').'(?![A-Z0-9_-])/', strtoupper($reference));
    }
}
