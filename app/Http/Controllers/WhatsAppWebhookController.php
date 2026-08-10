<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request)
    {
        Log::info('WhatsApp webhook payload', $request->all());

        $entry = $request->input('entry.0.changes.0.value.messages.0');
        if (!$entry) {
            return response('OK', 200);
        }

        $fromPhone = $entry['from'];
        $body = $entry['text']['body'] ?? null;
        $user = \App\Models\User::where('phone', '+' . ltrim($fromPhone, '+'))->first();

        \App\Models\WhatsAppMessage::create([
            'user_id' => $user?->id,
            'direction' => 'inbound',
            'phone' => $fromPhone,
            'body' => $body,
        ]);

        if (!$user || $user->status !== 'approved') {
            return response('OK', 200);
        }

        if ($body) {
            \App\Jobs\RouteInboundMessage::dispatch($user, $body);
        }

        return response('OK', 200);
    }
}