<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\UserApprovalController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WhatsAppWebhookController;

Route::get('/', function () {
    return view('welcome');
});

Route::redirect('/dashboard', '/transactions');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::resource('transactions', TransactionController::class)->except(['show']);
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [UserApprovalController::class, 'index'])->name('users.index');
    Route::post('/users/{user}/approve', [UserApprovalController::class, 'approve'])->name('users.approve');
});

Route::get('/webhook/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhook/whatsapp', [WhatsAppWebhookController::class, 'receive']);

require __DIR__.'/auth.php';
