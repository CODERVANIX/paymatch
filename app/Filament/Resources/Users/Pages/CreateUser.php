<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(auth()->user()?->role === 'admin', 403);
        $user = \App\Models\User::create($data);
        AuditEvent::record(auth()->id(), 'user_created', 'user', $user->id, $user->email.'; '.$user->role);
        return $user;
    }

    protected function getRedirectUrl(): string
    {
        return UserResource::getUrl('index');
    }
}
