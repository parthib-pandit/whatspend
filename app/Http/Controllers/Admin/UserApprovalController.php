<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class UserApprovalController extends Controller
{
    public function index(): View
    {
        $pendingUsers = User::where('status', 'pending')->latest()->get();

        return view('admin.users', compact('pendingUsers'));
    }

    public function approve(User $user): RedirectResponse
    {
        $user->update(['status' => 'approved']);

        return back()->with('status', "{$user->name} approved.");
    }
}