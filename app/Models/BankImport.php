<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankImport extends Model
{
    protected $fillable = ['file_name', 'account_label', 'file_sha256', 'row_count', 'imported_by'];
}
