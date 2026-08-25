<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\Transaction;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

class ResetDemoEnvironment extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Truncate and reseed the demo user\'s data to a clean, populated state';

    public function handle(DemoSeeder $seeder): int
    {
        $user = $seeder->findOrCreateDemoUser();

        Transaction::where('user_id', $user->id)->delete();
        Budget::where('user_id', $user->id)->delete();

        $seeder->seedFor($user);

        $this->info("Demo environment reset for user #{$user->id}.");

        return self::SUCCESS;
    }
}