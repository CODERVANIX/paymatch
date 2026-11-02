<?php

namespace App\Filament\Resources\PaymentOrders\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;

class PaymentOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')->label('Order code')->required()->maxLength(40)
                    ->helperText('Ask the customer to include this code in the bank transfer reference.'),
                TextInput::make('customer')->required()->maxLength(120),
                TextInput::make('amount')->label('Expected amount (LKR)')->required()
                    ->rule('regex:/^\d{1,12}(\.\d{1,2})?$/'),
            ]);
    }
}
