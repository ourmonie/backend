<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Create an administrative account for a freshly installed instance.
 *
 * This exists because credentials must never be seeded. A seeder runs on every
 * install path and prints what it made, which means a known super-admin password
 * in a repository is a known super-admin password on every server that ever ran
 * the migration. Instead the roles and permissions are seeded, and the first
 * human account is created deliberately, here, with a password this process
 * never learns in advance.
 */
class CreateAdmin extends Command
{
    protected $signature = 'ourmonie:create-admin
        {email : Email address for the new administrator}
        {--name= : Full name; prompted for when omitted}
        {--role=super_admin : Role code to assign}';

    protected $description = 'Create an administrative account with a chosen password';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('"'.$email.'" is not a valid email address.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('A user with that email already exists.');

            return self::FAILURE;
        }

        $role = Role::where('code', (string) $this->option('role'))->first();

        if ($role === null) {
            $this->error('No role with that code. Run php artisan db:seed --class=RbacSeeder first.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: $this->ask('Full name'));

        // Read with secret() so the password never lands in shell history,
        // process listings or the terminal scrollback.
        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        try {
            $this->validatePasswordStrength($password);
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_admin' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $user->roles()->syncWithoutDetaching([$role->id]);
        $user->forgetPermissionGraph();

        $this->info($role->name.' created: '.$email);

        return self::SUCCESS;
    }

    /**
     * The minimum is what the member-facing password rules already require, so
     * an administrator is never held to a lower standard than a member.
     */
    private function validatePasswordStrength(string $password): void
    {
        if (strlen($password) < 12) {
            throw ValidationException::withMessages([
                'password' => 'Use at least 12 characters.',
            ]);
        }

        if (preg_match('/^(.)\1+$/', $password) === 1) {
            throw ValidationException::withMessages([
                'password' => 'That password is a single repeated character.',
            ]);
        }
    }
}
