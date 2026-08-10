<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppClient
{
    protected string $token;
    protected string $phoneNumberId;
    protected string $apiVersion = 'v25.0';

    public function __construct()
    {
        $this->token = config('services.whatsapp.token');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
    }

    /**
     * Send a text message via the Meta Graph API.
     *
     * Transient failures (timeouts, 5xx) are retried automatically by the
     * HTTP client (2 extra attempts, 1s apart) before we give up. Permanent
     * failures (4xx — bad phone number, invalid token, etc.) are not
     * retried, since retrying won't change the outcome.
     *
     * This never throws — callers get an empty array back on total failure
     * and should treat that as "the message did not go out" without the
     * job itself failing/retrying (the transaction, if any, is already
     * saved by the time this is called — see ParseTransactionMessage).
     */
    public function sendText(string $toPhone, string $message): array
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        try {
            $response = Http::timeout(10)
                ->retry(2, 1000, function ($exception, $request) {
                    // Only retry on connection/timeout issues or 5xx responses,
                    // never on 4xx (those are permanent — bad number, bad token, etc.)
                    return $exception instanceof \Illuminate\Http\Client\ConnectionException;
                })
                ->withToken($this->token)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => $toPhone,
                    'type' => 'text',
                    'text' => [
                        'body' => $message,
                    ],
                ]);

            if ($response->failed()) {
                Log::error('WhatsApp send failed', [
                    'to' => $toPhone,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::error('WhatsApp send exception', [
                'to' => $toPhone,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Same as sendText() but returns whether it actually succeeded, for
     * call sites that need to know (rather than just fire-and-forget).
     */
    public function sendTextSucceeded(string $toPhone, string $message): bool
    {
        return $this->sendText($toPhone, $message) !== [];
    }
}