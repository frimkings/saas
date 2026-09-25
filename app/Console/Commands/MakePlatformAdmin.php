<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MakePlatformAdmin extends Command
{
    protected $signature = 'platform:make-admin
        {email : Developer email address}
        {--name= : Full name used when creating a new account}
        {--revoke : Revoke platform administrator access}
        {--reset-password : Reset to the temporary password and force a change at login}';
    protected $aliases = ['platform:create-admin'];
    protected $description = 'Create, grant, reset, or revoke platform administrator access';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Enter a valid email address.');
            return self::FAILURE;
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user && $this->option('revoke')) {
            $this->error('No user exists with that email address.');
            return self::FAILURE;
        }

        $created = false;
        if (! $user) {
            $name = trim((string) ($this->option('name') ?: str($email)->before('@')->replace(['.', '_', '-'], ' ')->title()));
            if ($name === '') {
                $this->error('The developer name cannot be empty.');
                return self::FAILURE;
            }

            $user = new User();
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'must_change_password' => true,
                'is_active' => true,
                'is_platform_admin' => true,
            ])->save();
            $created = true;
        }

        $attributes = ['is_platform_admin' => ! $this->option('revoke')];
        if ($this->option('reset-password')) {
            $attributes += [
                'password' => Hash::make('password'),
                'must_change_password' => true,
                'is_active' => true,
                'remember_token' => null,
            ];
        }
        $user->forceFill($attributes)->save();
        if ($created) {
            $this->info("Platform administrator created for {$email}.");
            $this->warn('Temporary password: password');
            $this->warn('A password change is required at first login.');
        } else {
            $this->info($this->option('revoke') ? 'Platform access revoked.' : 'Platform administrator granted.');
        }
        return self::SUCCESS;
    }
}
