<?php

namespace App\Observers;

use App\Models\User;
use App\Services\WhatsAppClient;

class UserObserver
{
    public function updated(User $user): void
    {
        if ($user->wasChanged('status') && $user->status === 'approved' && $user->getOriginal('status') === 'pending') {
            app(WhatsAppClient::class)->sendText(
                $user->phone,
                "You're approved! 🎉 Start logging expenses anytime — just message me."
            );
        }
    }
}