<?php

namespace BagoesPantera\CronMailer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

class ProcessPendingEmailsCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'cron-mail:process {--limit= : Override the configured batch size}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deliver the pending e-mails stored by the queueMail() helper.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $table = config('cron-mailer.table_name', 'pending_emails');
        $maxAttempts = (int) config('cron-mailer.max_attempts', 3);
        $batchSize = (int) ($this->option('limit') ?: config('cron-mailer.batch_size', 10));
        $deleteAfterSend = (bool) config('cron-mailer.delete_after_send', true);

        $rows = DB::table($table)
            ->where(function ($query) use ($maxAttempts) {
                $query->where('status', 'pending')
                    ->orWhere(function ($query) use ($maxAttempts) {
                        $query->where('status', 'failed')
                            ->where('attempts', '<', $maxAttempts);
                    });
            })
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();

        if ($rows->isEmpty()) {
            $this->components->info('No pending e-mails to process.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $mailable = $this->rebuild($row->mailable_class, json_decode($row->payload, true) ?? []);

                Mail::to($row->recipient_email)->send($mailable);

                if ($deleteAfterSend) {
                    DB::table($table)->where('id', $row->id)->delete();
                } else {
                    DB::table($table)->where('id', $row->id)->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
                }

                $sent++;
            } catch (Throwable $e) {
                DB::table($table)->where('id', $row->id)->update([
                    'attempts' => $row->attempts + 1,
                    'error_message' => $e->getMessage(),
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);

                report($e);

                $failed++;

                $this->components->twoColumnDetail($row->recipient_email, "failed: {$e->getMessage()}");
            }
        }

        $this->components->info("Processed {$rows->count()} e-mail(s): {$sent} sent, {$failed} failed.");

        return self::SUCCESS;
    }

    /**
     * Rehydrate the mailable, reloading any Eloquent model it references.
     *
     * @param  array<string, array<string, mixed>>  $payload
     */
    protected function rebuild(string $mailableClass, array $payload): object
    {
        $reflector = new ReflectionClass($mailableClass);
        $constructor = $reflector->getConstructor();

        $arguments = [];
        $resolved = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $value = $payload[$parameter->getName()] ?? null;

            if (! is_array($value)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();

                    continue;
                }

                if ($parameter->allowsNull()) {
                    $arguments[] = null;

                    continue;
                }

                throw new InvalidArgumentException(
                    "Unable to resolve constructor parameter [{$parameter->getName()}] for [{$mailableClass}]."
                );
            }

            $arguments[] = ($value['__is_model'] ?? false) === true
                ? $value['class']::findOrFail($value['id'])
                : ($value['value'] ?? null);

            $resolved[] = $parameter->getName();
        }

        $mailableInstance = $reflector->newInstanceArgs($arguments);

        $this->hydratePublicProperties($mailableInstance, $payload, $resolved);

        return $mailableInstance;
    }

    /**
     * Restore public properties that were populated outside of the constructor.
     *
     * Mailables often declare promoted-less properties and assign them after
     * instantiation, so the constructor pass alone cannot restore them.
     *
     * @param  array<string, array<string, mixed>>  $payload
     * @param  array<int, string>  $resolved  Constructor names already applied to the instance.
     */
    protected function hydratePublicProperties(object $mailableInstance, array $payload, array $resolved = []): void
    {
        $reflector = new ReflectionClass($mailableInstance);

        foreach ($payload as $propertyName => $value) {
            if (! is_array($value) || in_array($propertyName, $resolved, true)) {
                continue;
            }

            $property = $reflector->hasProperty($propertyName)
                ? $reflector->getProperty($propertyName)
                : null;

            if ($property === null || ! $property->isPublic() || $property->isStatic()) {
                continue;
            }

            $mailableInstance->{$propertyName} = ($value['__is_model'] ?? false) === true
                ? $value['class']::findOrFail($value['id'])
                : ($value['value'] ?? null);
        }
    }
}
