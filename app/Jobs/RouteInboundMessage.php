<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\InboundMessageRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RouteInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public User $user,
        public string $message,
    ) {}

    public function handle(InboundMessageRouter $router): void
    {
        $router->route($this->user, $this->message);
    }
}