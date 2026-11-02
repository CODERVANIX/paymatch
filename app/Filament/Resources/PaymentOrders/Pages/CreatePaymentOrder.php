<?php

namespace App\Filament\Resources\PaymentOrders\Pages;

use App\Filament\Resources\PaymentOrders\PaymentOrderResource;
use Filament\Resources\Pages\CreateRecord;
use App\Services\PaymentWorkflow;
use Illuminate\Database\Eloquent\Model;

class CreatePaymentOrder extends CreateRecord
{
    protected static string $resource = PaymentOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(PaymentWorkflow::class)->createOrder($data['code'], $data['customer'], $data['amount'], auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return PaymentOrderResource::getUrl('index');
    }
}
