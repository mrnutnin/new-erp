<?php

namespace App\Modules\Crm\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class CrmWorkNotification extends Notification implements ShouldQueue
{
    use Queueable;
    public function __construct(private readonly array $payload) { $this->onQueue('default'); }
    public function via(object $notifiable): array
    {
        return config('webpush.vapid.public_key') && config('webpush.vapid.private_key') ? ['database', WebPushChannel::class] : ['database'];
    }
    public function toArray(object $notifiable): array { return $this->payload; }
    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)->title((string) ($this->payload['title'] ?? 'MintERP CRM'))->body((string) ($this->payload['message'] ?? ''))
            ->icon('/images/alexiasoft-logo.png')->badge('/images/alexiasoft-logo.png')->tag('crm-'.($this->payload['kind'] ?? 'notification'))
            ->action('เปิดดู', 'open')->data(['url' => $this->payload['url'] ?? '/crm/notifications'])->options(['TTL' => 3600]);
    }
}
