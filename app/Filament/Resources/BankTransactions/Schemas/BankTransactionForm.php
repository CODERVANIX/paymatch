<?php

namespace App\Filament\Resources\BankTransactions\Schemas;

use Filament\Schemas\Schema;

class BankTransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }
}
