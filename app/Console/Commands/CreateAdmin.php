<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'paymatch:create-admin';
    protected $description = 'Create the first PayMatch administrator interactively';

    public function handle(): int
    {
        if (User::where('role', 'admin')->exists()) {
            $this->error('An administrator already exists. Add staff from the Users page.');
            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Name'));
        $email = trim((string) $this->ask('Email'));
        $password = (string) $this->secret('Password (at least 12 characters)');
        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());
            return self::FAILURE;
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => 'admin']);
        AuditEvent::record($user->id, 'admin_bootstrapped', 'user', $user->id, $user->email);
        $this->info('Administrator created. Sign in at /admin.');
        return self::SUCCESS;
    }
}
