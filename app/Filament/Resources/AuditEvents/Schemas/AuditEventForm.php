<?php

namespace App\Filament\Resources\AuditEvents\Schemas;

use Filament\Schemas\Schema;

class AuditEventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }
}
