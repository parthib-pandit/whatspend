<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;
use App\Support\PhoneNumberNormalizer;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view (phone + OTP + new password form).
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * Verifies the OTP first, before touching the password at all — if
     * verification fails, nothing about the user record changes and the OTP
     * stays valid for a retry (it's only consumed on a successful reset,
     * see PasswordResetService::consumeOtp()).
     */
    public function store(Request $request, PasswordResetService $passwordResetService): RedirectResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
            'otp_code' => ['required', 'string'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $normalizedPhone = PhoneNumberNormalizer::toStorageFormat($request->phone);

        if (!$passwordResetService->verifyOtp($normalizedPhone, $request->otp_code)) {
            return back()
                ->withInput($request->only('phone'))
                ->withErrors(['otp_code' => 'That code is invalid or has expired.']);
        }

        $user = User::where('phone', $normalizedPhone)->first();

        if (!$user) {
            return back()
                ->withInput($request->only('phone'))
                ->withErrors(['phone' => 'We could not find an account with that phone number.']);
        }

        $user->forceFill([
            'password' => Hash::make($request->password),
            'remember_token' => Str::random(60),
        ])->save();

        $passwordResetService->consumeOtp($normalizedPhone);

        event(new PasswordReset($user));

        return redirect()->route('login')->with('status', __('Your password has been reset.'));
    }
}