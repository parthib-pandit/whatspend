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

    /**
     * Uploads a local file to Meta's media endpoint and returns the media_id,
     * or null on failure. Same retry/logging conventions as sendText().
     */
    protected function uploadMedia(string $filePath, string $mimeType): ?string
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/media";

        try {
            $response = Http::timeout(20)
                ->retry(2, 1000, function ($exception, $request) {
                    return $exception instanceof \Illuminate\Http\Client\ConnectionException;
                })
                ->withToken($this->token)
                ->attach('file', file_get_contents($filePath), basename($filePath), ['Content-Type' => $mimeType])
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'type' => $mimeType,
                ]);

            if ($response->failed()) {
                Log::error('WhatsApp media upload failed', [
                    'file' => $filePath,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json('id');
        } catch (\Throwable $e) {
            Log::error('WhatsApp media upload exception', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Sends a local file as a WhatsApp document message: uploads it to
     * Meta's media endpoint, then sends a document message referencing the
     * returned media_id. Never throws — returns false if either step fails,
     * same fire-and-forget contract as sendText(). Caller owns the file's
     * lifecycle (this method does not delete $filePath).
     */
    public function sendDocument(string $toPhone, string $filePath, string $filename): bool
    {
        $mimeType = match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'csv' => 'text/plain',
            default => 'application/octet-stream',
        };

        $mediaId = $this->uploadMedia($filePath, $mimeType);

        if (!$mediaId) {
            return false;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        try {
            $response = Http::timeout(10)
                ->retry(2, 1000, function ($exception, $request) {
                    return $exception instanceof \Illuminate\Http\Client\ConnectionException;
                })
                ->withToken($this->token)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => $toPhone,
                    'type' => 'document',
                    'document' => [
                        'id' => $mediaId,
                        'filename' => $filename,
                    ],
                ]);

            if ($response->failed()) {
                Log::error('WhatsApp document send failed', [
                    'to' => $toPhone,
                    'media_id' => $mediaId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('WhatsApp document send exception', [
                'to' => $toPhone,
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}