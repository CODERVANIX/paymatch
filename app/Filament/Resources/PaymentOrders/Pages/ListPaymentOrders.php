<?php

namespace App\Filament\Resources\PaymentOrders\Pages;

use App\Filament\Resources\PaymentOrders\PaymentOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentOrders extends ListRecords
{
    protected static string $resource = PaymentOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
