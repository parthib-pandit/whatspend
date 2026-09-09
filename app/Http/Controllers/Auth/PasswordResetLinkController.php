<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Support\PhoneNumberNormalizer;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset OTP request.
     */
    public function store(Request $request, PasswordResetService $passwordResetService): RedirectResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $normalizedPhone = PhoneNumberNormalizer::toStorageFormat($request->phone);
        $user = User::where('phone', $normalizedPhone)->first();

        if ($user) {
            $sent = $passwordResetService->generateAndSendOtp($normalizedPhone);

            if (!$sent) {
                return back()->withErrors([
                    'phone' => 'Could not send the reset code. Make sure you\'ve messaged the WhatsApp bot recently, then try again.',
                ]);
            }
        }

        return redirect()->route('password.reset', ['phone' => $normalizedPhone]);
    }
}