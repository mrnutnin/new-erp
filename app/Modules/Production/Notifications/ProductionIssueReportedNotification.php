<?php

namespace App\Modules\Production\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class ProductionIssueReportedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $documentNumber, private readonly string $description, private readonly string $url)
    {
        $this->onQueue('default');
    }

    public function via(object $notifiable): array
    {
        return config('webpush.vapid.public_key') && config('webpush.vapid.private_key') ? ['database', WebPushChannel::class] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'PRODUCTION_ISSUE', 'title' => 'มีปัญหาหน้างานผลิต', 'message' => $this->documentNumber.' · '.$this->description, 'url' => $this->url];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)->title('มีปัญหาหน้างานผลิต')->body($this->documentNumber.' · '.$this->description)
            ->icon('/images/alexiasoft-logo.png')->badge('/images/alexiasoft-logo.png')->tag('production-issue')
            ->action('เปิดดู', 'open')->data(['url' => $this->url])->options(['TTL' => 3600]);
    }
}
