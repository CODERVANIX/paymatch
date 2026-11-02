<?php

namespace App\Filament\Resources\BankImports\Pages;

use App\Filament\Resources\BankImports\BankImportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankImports extends ListRecords
{
    protected static string $resource = BankImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
