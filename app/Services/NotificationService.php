<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Support\AppSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notification centre. Notifications are de-duplicated by key while unresolved; optional e-mail
 * copies are sent only when SMTP notifications are enabled in Settings, and only after commit.
 */
class NotificationService
{
    public function __construct(protected AppSettings $settings) {}

    /** @param array{type:string, severity?:string, title:string, message?:string, link?:string, permission?:string, user_id?:int} $data */
    public function raise(string $dedupeKey, array $data, bool $email = false): AppNotification
    {
        $existing = AppNotification::query()->where('dedupe_key', $dedupeKey)->first();
        $wasOpen = $existing && $existing->resolved_at === null;

        $notification = AppNotification::query()->updateOrCreate(['dedupe_key' => $dedupeKey], $data + ['resolved_at' => null]);
        if (! $wasOpen) {
            // Re-opened or new: show as unread again.
            DB::table('notification_reads')->where('notification_id', $notification->id)->delete();
            if ($email) {
                $this->emailAfterCommit($notification);
            }
        }

        return $notification;
    }

    public function resolve(string $dedupeKey): void
    {
        AppNotification::query()->where('dedupe_key', $dedupeKey)->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    protected function emailAfterCommit(AppNotification $notification): void
    {
        if (! $this->settings->get('notify_email_enabled')) {
            return;
        }
        $recipients = array_filter(array_map('trim', explode(',', (string) $this->settings->get('notify_emails'))));
        if (! $recipients) {
            return;
        }
        DB::afterCommit(function () use ($notification, $recipients) {
            try {
                Mail::raw(
                    $notification->title."\n\n".($notification->message ?? '')."\n\n".rtrim((string) config('app.url'), '/').$notification->link,
                    fn ($m) => $m->to($recipients)->subject('[Stock alert] '.$notification->title),
                );
            } catch (\Throwable $e) {
                // E-mail is optional: the in-app notification remains the source of truth.
                Log::warning('Notification e-mail failed: '.$e->getMessage());
            }
        });
    }
}
