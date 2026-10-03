<?php

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Pantera\CronMailer\Services\MailableSerializer;

if (! function_exists('queueMail')) {
    /**
     * Persist a mailable for later delivery instead of sending it right away.
     *
     * @param  string|array<int, string>  $recipients
     */
    function queueMail(Mailable $mailable, string|array $recipients): bool
    {
        try {
            $recipients = is_array($recipients) ? $recipients : [$recipients];

            $payload = (new MailableSerializer())->serialize($mailable);
            $encodedPayload = json_encode($payload);

            $now = now();

            $rows = array_map(fn (string $recipient): array => [
                'recipient_email' => $recipient,
                'mailable_class' => get_class($mailable),
                'payload' => $encodedPayload,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], $recipients);

            DB::table(config('cron-mailer.table_name', 'pending_emails'))->insert($rows);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}