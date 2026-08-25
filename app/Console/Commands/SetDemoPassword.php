<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetDemoPassword extends Command
{
    protected $signature = 'demo:set-password {password}';

    protected $description = 'Set a fixed password on the demo user, so it can be shared publicly and survives demo:reset';

    public function handle(): int
    {
        $demo = User::where('is_demo', true)->first();

        if (!$demo) {
            $this->error('No demo user found. Run demo:reset first.');
            return self::FAILURE;
        }

        $demo->password = $this->argument('password');
        $demo->save();

        $this->info("Password set for demo user #{$demo->id} ({$demo->email}).");

        return self::SUCCESS;
    }
}