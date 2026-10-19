<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class InviteUser extends Command
{
    protected $signature = 'issuemanager:invite {email : Email of the YouTrack/Hub account} {--name= : Display name} {--remove : Remove the user and their history}';

    protected $description = 'Allow (or remove) an account that can log in through the YouTrack Hub';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));

        if ($this->option('remove')) {
            $user = User::whereRaw('lower(email) = ?', [$email])->first();
            if (! $user || ! $this->confirm("Delete {$user->email} and all their conversations?")) {
                $this->warn($user ? 'Cancelled.' : 'User not found.');

                return self::FAILURE;
            }
            $user->delete();
            $this->info('User removed.');

            return self::SUCCESS;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email.');

            return self::FAILURE;
        }

        $user = User::firstOrCreate(['email' => $email], ['name' => $this->option('name') ?: strstr($email, '@', true)]);
        $this->info($user->wasRecentlyCreated ? "Invited {$email}." : "{$email} was already invited.");

        return self::SUCCESS;
    }
}
