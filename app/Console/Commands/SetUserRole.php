<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

class SetUserRole extends Command
{
    protected $signature = 'user:role {email} {role : user, moderator or admin}';

    protected $description = 'Give an account a role. Roles cannot be set through the API.';

    public function handle(): int
    {
        if (! in_array($this->argument('role'), User::ROLES, true)) {
            $this->error('Role must be one of: '.implode(', ', User::ROLES));

            return self::FAILURE;
        }
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }
        $before = $user->role;
        $user->forceFill(['role' => $this->argument('role')])->save();
        AuditLog::record('role', $user, ['role' => [$before, $user->role]], 'set from the command line');
        $this->info("{$user->email} is now {$user->role}");

        return self::SUCCESS;
    }
}
