<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankTransaction extends Model
{
    protected $fillable = ['bank_import_id', 'account_label', 'booked_on', 'bank_id', 'reference', 'amount_cents', 'fingerprint', 'duplicate_of_id', 'matched_order_id'];

    protected function casts(): array
    {
        return ['booked_on' => 'date'];
    }
}
