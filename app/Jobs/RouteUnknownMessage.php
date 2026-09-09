<?php

namespace App\Jobs;

use App\Services\InboundMessageRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RouteUnknownMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public string $phone,
        public string $message,
    ) {}

    public function handle(InboundMessageRouter $router): void
    {
        $router->routeUnknown($this->phone, $this->message);
    }
}