<?php

namespace BagoesPantera\CronMailer\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use BagoesPantera\CronMailer\Tests\Fixtures\OrderShipped;
use BagoesPantera\CronMailer\Tests\Fixtures\User;
use BagoesPantera\CronMailer\Tests\Fixtures\WelcomeBack;
use BagoesPantera\CronMailer\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CronMailerTest extends TestCase
{
    #[Test]
    public function it_stores_a_pending_record_for_a_single_recipient(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $stored = queueMail(new OrderShipped($user, 'ORD-1'), $user->email);

        $this->assertTrue($stored);
        $this->assertDatabaseCount('pending_emails', 1);

        $row = DB::table('pending_emails')->first();

        $this->assertSame($user->email, $row->recipient_email);
        $this->assertSame(OrderShipped::class, $row->mailable_class);
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, (int) $row->attempts);
    }

    #[Test]
    public function it_stores_one_record_per_recipient_in_a_single_batch(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $stored = queueMail(new OrderShipped($user, 'ORD-2'), ['one@example.com', 'two@example.com']);

        $this->assertTrue($stored);
        $this->assertDatabaseCount('pending_emails', 2);
        $this->assertEqualsCanonicalizing(
            ['one@example.com', 'two@example.com'],
            DB::table('pending_emails')->pluck('recipient_email')->all()
        );
    }

    #[Test]
    public function it_serializes_eloquent_models_as_a_class_key_reference(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        queueMail(new OrderShipped($user, 'ORD-3'), $user->email);

        $payload = json_decode(DB::table('pending_emails')->value('payload'), true);

        $this->assertTrue($payload['user']['__is_model']);
        $this->assertSame(User::class, $payload['user']['class']);
        $this->assertSame($user->getKey(), $payload['user']['id']);

        $this->assertFalse($payload['orderNumber']['__is_model']);
        $this->assertSame('ORD-3', $payload['orderNumber']['value']);
    }

    #[Test]
    public function it_reports_and_returns_false_when_the_table_is_missing(): void
    {
        DB::statement('DROP TABLE pending_emails');

        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $this->assertFalse(queueMail(new OrderShipped($user, 'ORD-4'), $user->email));
    }

    #[Test]
    public function it_reconstructs_and_sends_the_mailable_then_removes_the_row(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        Mail::fake();

        queueMail(new OrderShipped($user, 'ORD-5'), $user->email);

        $this->artisan('cron-mail:process')->assertSuccessful();

        $this->assertDatabaseCount('pending_emails', 0);

        Mail::assertSent(OrderShipped::class, function (OrderShipped $mailable) use ($user) {
            return $mailable->hasTo($user->email)
                && $mailable->orderNumber === 'ORD-5'
                && $mailable->user->is($user);
        });
    }

    #[Test]
    public function it_hydrates_public_properties_assigned_outside_the_constructor(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        Mail::fake();

        $mailable = new WelcomeBack();
        $mailable->user = $user;
        $mailable->headline = 'Welcome back!';

        queueMail($mailable, $user->email);

        $this->artisan('cron-mail:process')->assertSuccessful();

        $this->assertDatabaseCount('pending_emails', 0);

        Mail::assertSent(WelcomeBack::class, function (WelcomeBack $mailable) use ($user) {
            return $mailable->hasTo($user->email)
                && $mailable->headline === 'Welcome back!'
                && $mailable->user->is($user);
        });
    }

    #[Test]
    public function it_marks_the_row_as_failed_when_rehydration_throws(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        DB::table('pending_emails')->insert([
            'recipient_email' => $user->email,
            'mailable_class' => OrderShipped::class,
            'payload' => json_encode([
                'user' => ['__is_model' => true, 'class' => User::class, 'id' => 9999],
                'orderNumber' => ['__is_model' => false, 'value' => 'ORD-7'],
            ]),
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('cron-mail:process')->assertSuccessful();

        $row = DB::table('pending_emails')->first();

        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotEmpty($row->error_message);
    }

    #[Test]
    public function it_skips_rows_that_exhausted_the_max_attempts(): void
    {
        config()->set('cron-mailer.max_attempts', 3);

        User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        DB::table('pending_emails')->insert([
            'recipient_email' => 'gone@example.com',
            'mailable_class' => OrderShipped::class,
            'payload' => json_encode(['orderNumber' => ['__is_model' => false, 'value' => 'ORD-8']]),
            'status' => 'failed',
            'attempts' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('cron-mail:process')->assertSuccessful();

        $this->assertSame(3, (int) DB::table('pending_emails')->value('attempts'));
    }

    #[Test]
    public function it_retries_failed_rows_below_the_max_attempts(): void
    {
        config()->set('cron-mailer.max_attempts', 3);

        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        Mail::fake();

        DB::table('pending_emails')->insert([
            'recipient_email' => $user->email,
            'mailable_class' => WelcomeBack::class,
            'payload' => json_encode([
                'user' => ['__is_model' => true, 'class' => User::class, 'id' => $user->getKey()],
                'headline' => ['__is_model' => false, 'value' => 'Retry'],
            ]),
            'status' => 'failed',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('cron-mail:process')->assertSuccessful();

        $this->assertDatabaseCount('pending_emails', 0);

        Mail::assertSent(WelcomeBack::class);
    }

    #[Test]
    public function it_keeps_the_row_as_an_audit_trail_when_delete_after_send_is_disabled(): void
    {
        config()->set('cron-mailer.delete_after_send', false);

        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        Mail::fake();

        queueMail(new OrderShipped($user, 'ORD-10'), $user->email);

        $this->artisan('cron-mail:process')->assertSuccessful();

        $this->assertDatabaseCount('pending_emails', 1);

        $row = DB::table('pending_emails')->first();

        $this->assertSame('sent', $row->status);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->error_message);
        $this->assertSame(0, (int) $row->attempts);

        Mail::assertSent(OrderShipped::class);
    }

    #[Test]
    public function it_clears_a_previous_error_when_the_retry_succeeds_with_audit_trail_enabled(): void
    {
        config()->set('cron-mailer.delete_after_send', false);

        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        Mail::fake();

        DB::table('pending_emails')->insert([
            'recipient_email' => $user->email,
            'mailable_class' => OrderShipped::class,
            'payload' => json_encode([
                'user' => ['__is_model' => true, 'class' => User::class, 'id' => $user->getKey()],
                'orderNumber' => ['__is_model' => false, 'value' => 'ORD-11'],
            ]),
            'status' => 'failed',
            'attempts' => 1,
            'error_message' => 'SMTP connection timed out',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('cron-mail:process')->assertSuccessful();

        $row = DB::table('pending_emails')->first();

        $this->assertSame('sent', $row->status);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->error_message);
        $this->assertSame(1, (int) $row->attempts);
    }

    #[Test]
    public function it_never_reprocesses_rows_already_marked_as_sent(): void
    {
        config()->set('cron-mailer.delete_after_send', false);

        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        Mail::fake();

        queueMail(new OrderShipped($user, 'ORD-12'), $user->email);

        $this->artisan('cron-mail:process')->assertSuccessful();
        $this->artisan('cron-mail:process')->assertSuccessful();

        $this->assertDatabaseCount('pending_emails', 1);

        Mail::assertSentCount(1);
    }

    #[Test]
    public function it_deletes_the_row_by_default_when_delete_after_send_is_enabled(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        Mail::fake();

        queueMail(new OrderShipped($user, 'ORD-13'), $user->email);

        $this->artisan('cron-mail:process')->assertSuccessful();

        $this->assertDatabaseCount('pending_emails', 0);
    }

    #[Test]
    public function it_only_processes_the_configured_batch_size(): void
    {
        config()->set('cron-mailer.batch_size', 2);

        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        queueMail(new OrderShipped($user, 'ORD-9'), ['a@example.com', 'b@example.com', 'c@example.com']);

        $this->assertDatabaseCount('pending_emails', 3);

        $this->artisan('cron-mail:process')->assertSuccessful();

        $this->assertDatabaseCount('pending_emails', 1);
    }
}