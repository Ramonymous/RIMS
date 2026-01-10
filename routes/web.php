<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use Illuminate\Http\Request;
 
// Users will be redirected to this route if not logged in
Volt::route('/login', 'login')->name('login');

// Telegram Webhook (must be outside auth middleware)
Route::post('/telegram/webhook', function (Request $request) {
    try {
        $data = $request->all();
        Log::info('Telegram webhook received', $data);
        
        // Check if this is a /start command
        if (isset($data['message']['text'])) {
            $text = $data['message']['text'];
            $chatId = $data['message']['chat']['id'] ?? null;
            
            // Extract start parameter: /start user_123
            if (str_starts_with($text, '/start ') && $chatId) {
                $startParam = trim(str_replace('/start ', '', $text));
                
                try {
                    // Decode the user ID
                    $decoded = base64_decode($startParam);
                    
                    if (str_starts_with($decoded, 'user_')) {
                        $userId = (int) str_replace('user_', '', $decoded);
                        
                        // Find and update user
                        $user = User::find($userId);
                        if ($user) {
                            $user->telegram_user_id = (string) $chatId;
                            $user->save();
                            
                            // Send confirmation message to user
                            $botToken = config('services.telegram-bot-api.token');
                            $userName = $user->name;
                            
                            $message = "✅ *Berhasil Terhubung!*\n\n";
                            $message .= "Halo {$userName}! Akun Telegram Anda telah berhasil dihubungkan.\n\n";
                            $message .= "Anda sekarang akan menerima notifikasi untuk:\n";
                            $message .= "• 🔔 Permintaan part baru\n";
                            $message .= "• 📦 Update status permintaan\n\n";
                            $message .= "Terima kasih telah menggunakan sistem RIMS!";
                            
                            Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                                'chat_id' => $chatId,
                                'text' => $message,
                                'parse_mode' => 'Markdown'
                            ]);
                            
                            Log::info('User linked to Telegram', [
                                'user_id' => $userId,
                                'chat_id' => $chatId
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to link Telegram user', [
                        'error' => $e->getMessage(),
                        'start_param' => $startParam
                    ]);
                }
            }
        }
        
        return response()->json(['ok' => true]);
    } catch (\Exception $e) {
        Log::error('Telegram webhook error', ['error' => $e->getMessage()]);
        return response()->json(['ok' => false], 500);
    }
})->name('telegram.webhook');
 
// Define the logout
Route::get('/logout', function () {
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
 
    return redirect('/login');
});


// Protected routes here
Route::middleware('auth')->group(function () {
    Volt::route('/', 'dashboard')->name('dashboard');

    Volt::route('/users', 'manage.users')->name('users');
    Volt::route('/parts', 'manage.parts')->name('parts');
    Volt::route('/receivings', 'part-receiving')->name('part-receiving');
    Volt::route('/requests', 'part-request')->name('part-request');
    Volt::route('/request-list', 'request-list')->name('part-request-list');
    Volt::route('/stock-movements', 'stock-movements')->name('stock-movements');
    Volt::route('/telegram-settings', 'telegram-settings')->name('telegram-settings');

});
