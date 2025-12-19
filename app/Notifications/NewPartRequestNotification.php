<?php

namespace App\Notifications;

use App\Models\Request as StockRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\TelegramMessage;

class NewPartRequestNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected StockRequest $request,
        protected int $itemCount,
        protected int $totalQuantity
    ) {}

    public function via($notifiable): array
    {
        $channels = [];
        
        // Add WebPush if user has subscriptions
        if ($notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }
        
        // Add Telegram if user has telegram_user_id
        if ($notifiable->telegram_user_id) {
            $channels[] = TelegramChannel::class;
        }
        
        return $channels;
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('🔔 Permintaan Part Baru!')
            ->body("Ada permintaan baru untuk {$this->request->destination} dengan {$this->itemCount} jenis part ({$this->totalQuantity} KBN)")
            ->icon('/favicon.ico')
            ->badge('/favicon.ico')
            ->tag('new-request-' . $this->request->id)
            ->data([
                'request_id' => $this->request->id,
                'destination' => $this->request->destination,
                'item_count' => $this->itemCount,
                'total_quantity' => $this->totalQuantity,
                'url' => url('/request-list'),
            ])
            ->action('Lihat Detail', 'view-request');
    }

    public function toTelegram($notifiable): TelegramMessage
    {
        $url = url('/request-list');
        $requester = $this->request->requestedBy->name ?? 'Unknown';
        
        $message = TelegramMessage::create()
            ->to($notifiable->telegram_user_id)
            ->content("*🔔 Permintaan Part Baru!*")
            ->line("")
            ->line("📦 *Detail Permintaan:*")
            ->line("• Tujuan: {$this->request->destination}")
            ->line("• Jenis Part: {$this->itemCount}")
            ->line("• Total KBN: {$this->totalQuantity}")
            ->line("• Diminta oleh: {$requester}")
            ->line("")
            ->line("*📋 Daftar Item:*");
        
        // Add header
        $message->line("`PART NUMBER      | STATUS`");
        $message->line("`─────────────────┼─────────`");
        
        // Add items
        $this->request->items()->with('part')->get()->each(function ($item) use ($message) {
            $partNumber = $item->part->part_number ?? 'Unknown';
            $status = $item->is_urgent ? '⚠️ URGENT' : '✓ Normal';
            // Format with consistent spacing for monospace font
            $formatted = sprintf("%-16s | %s", $partNumber, $status);
            $message->line("`{$formatted}`");
        });
        
        $message->line("")
            ->line("Silakan proses permintaan ini segera.")
            ->button('Lihat Detail', $url);
        
        return $message;
    }
}
