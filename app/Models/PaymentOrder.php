<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentOrder extends Model
{
    protected $fillable = ['code', 'customer', 'amount_cents', 'status', 'matched_transaction_id', 'created_by', 'released_by', 'released_at', 'release_note'];

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'matched_transaction_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
