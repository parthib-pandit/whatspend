<?php

namespace App\Services;

use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Support\Str;
use App\Support\PhoneNumberNormalizer;

class PasswordResetService
{
    protected int $otpLength = 6;
    protected int $expiryMinutes = 10;

    public function __construct(protected WhatsAppClient $whatsAppClient)
    {
    }

    /**
     * Generates a fresh OTP for the given phone, stores it, and sends it via
     * WhatsApp. Returns true only if the message actually went out — if
     * WhatsApp delivery fails, the OTP row still exists but is effectively
     * useless, so callers should treat a false return as "reset unavailable
     * right now" rather than silently proceeding.
     */
    
    public function generateAndSendOtp(string $phone): bool
    {
        $phone = PhoneNumberNormalizer::toStorageFormat($phone);
        $otpCode = (string) random_int(100000, 999999);

        PasswordResetOtp::create([
            'phone' => $phone,
            'otp_code' => $otpCode,
            'expires_at' => now()->addMinutes($this->expiryMinutes),
        ]);

        return $this->whatsAppClient->sendOtp($phone, $otpCode);
    }

    /**
     * Verifies the OTP for a phone. Checks the most recent (non-expired) row
     * for that phone. Does NOT delete/consume it here — call
     * consumeOtp() separately once the password itself has actually been
     * changed, so a verified-but-not-yet-submitted OTP isn't burned early.
     */
    
    public function verifyOtp(string $phone, string $otpCode): bool
    {
        $phone = PhoneNumberNormalizer::toStorageFormat($phone);

        $otp = PasswordResetOtp::where('phone', $phone)
            ->orderByDesc('created_at')
            ->first();

        if (!$otp || $otp->isExpired()) {
            return false;
        }

        return hash_equals($otp->otp_code, $otpCode);
    }


    /**
     * Deletes all OTP rows for a phone — call this once the password has
     * actually been reset, so the code can't be reused.
     */
    
    public function consumeOtp(string $phone): void
    {
        $phone = PhoneNumberNormalizer::toStorageFormat($phone);

        PasswordResetOtp::where('phone', $phone)->delete();
    }
}