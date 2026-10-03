# Laravel Cron Mailer

A database-driven outbox mailer for Laravel without queue workers. Send asynchronous e-mails seamlessly via Laravel Scheduler (Cron).

<p align="center">
  <a href="https://packagist.org/packages/bagoespantera/laravel-cron-mailer">
    <img src="https://img.shields.io/packagist/v/bagoespantera/laravel-cron-mailer?label=Latest%20Version" alt="Latest Version">
  </a>
  <a href="https://github.com/bagoespantera/laravel-cron-mailer/blob/master/LICENSE">
    <img src="https://img.shields.io/packagist/l/bagoespantera/laravel-cron-mailer?label=License" alt="License: MIT">
  </a>
  <a href="https://packagist.org/packages/bagoespantera/laravel-cron-mailer">
    <img src="https://img.shields.io/packagist/dt/bagoespantera/laravel-cron-mailer?label=Total%20Downloads" alt="Total Downloads">
  </a>
</p>

---

## Key Features

- **Zero Queue Worker Setup** — No Redis, no Supervisor, no `queue:work` daemon required. E-mails are stored in your existing database and delivered straight from the scheduler.
- **Non-Blocking Enqueueing** — The global `queueMail()` helper writes to your DB inside a try-catch, reports failures via Laravel's `report()`, and never bubbles an exception up to the HTTP request.
- **Auto-Serialization via Reflection & Public Property Hydration** — Every public property of your mailable is captured. Eloquent models are stored as `[class, primary key]` pairs and reloaded with fresh data at send time via `findOrFail()`. Supports both constructor property promotion _and_ free-form public properties.
- **Automatic Retry & Per-Row Isolation** — A failure for one recipient increments its `attempts` counter, captures the exception message, and moves on. A broken row never blocks the rest of the batch.
- **Laravel 10 – 13 Compatibility** — A single package version runs on Laravel 10, 11, 12, and 13 thanks to a wide `illuminate/*` constraint matrix.

## Requirements

| Dependency | Constraint                                                                 |
| ---------- | -------------------------------------------------------------------------- |
| PHP        | `^8.2`                                                                     |
| Laravel    | `^10.0 \|\| ^11.0 \|\| ^12.0 \|\| ^13.0`                                   |
| Database   | Any PDO driver supported by Laravel (`mysql`, `pgsql`, `sqlite`, `sqlsrv`) |

## Installation

### Step 1 — Install via Composer

```bash
composer require bagoespantera/laravel-cron-mailer
```

The [CronMailerServiceProvider](src/CronMailerServiceProvider.php) is registered automatically through Laravel's package discovery.

### Step 2 — Publish Configuration & Migration

```bash
php artisan vendor:publish --provider="BagoesPantera\CronMailer\CronMailerServiceProvider"
```

This copies:

- `config/cron-mailer.php` — tunable knobs for the outbox
- `database/migrations/*_create_pending_emails_table.php` — the storage table

> Both publish tags (`cron-mailer-config`, `cron-mailer-migrations`) are also available if you need to publish them separately.

### Step 3 — Run the Migration

```bash
php artisan migrate
```

This creates the outbox table. Its name follows the value of `cron-mailer.table_name` in your config (see below).

## Configuration

All configuration lives in [config/cron-mailer.php](config/cron-mailer.php). After publishing the file, the following options are available:

| Key                 | Default            | Description                                                                                                                    |
| ------------------- | ------------------ | ------------------------------------------------------------------------------------------------------------------------------ |
| `table_name`        | `'pending_emails'` | Database table used as the outbox. The migration reads this key dynamically, so rename it freely before running `migrate`.     |
| `max_attempts`      | `3`                | Hard cap on delivery attempts. Once a row's `attempts` column reaches this value the worker skips it permanently.              |
| `batch_size`        | `10`               | Maximum number of rows pulled from the outbox on every worker run. Keep it small enough to stay inside the scheduler's window. |
| `delete_after_send` | `true`             | What happens to a row after a successful delivery. `true` deletes it; `false` retains it as an audit trail (see below).        |

### Delivery Audit Trail (`delete_after_send`)

By default a successfully delivered e-mail is **deleted** from the outbox, so the table only ever holds work that still needs doing. Set `delete_after_send` to `false` if you need to keep a record of what was sent:

```php
// config/cron-mailer.php
'delete_after_send' => false,
```

With that setting the worker marks the row instead of removing it:

| Column          | Value after a successful send                |
| --------------- | -------------------------------------------- |
| `status`        | `sent`                                       |
| `sent_at`       | timestamp of the successful delivery         |
| `error_message` | `null` (cleared, even on a successful retry) |
| `attempts`      | left untouched, so you keep the retry count  |

```sql
-- what your outbox looks like afterwards
SELECT recipient_email, status, attempts, sent_at FROM pending_emails;
```

A few things worth knowing:

- **`sent` rows are never re-processed.** The worker only selects `pending` and `failed` rows, so retained history is inert and safe to leave in place.
- **You own the cleanup.** With `delete_after_send => false` the table grows forever unless you prune it. A scheduled `DB::table('pending_emails')->where('status', 'sent')->where('sent_at', '<', now()->subDays(30))->delete();` is a common companion.
- **Failed rows are unaffected** — they stay in the table with `status = 'failed'` regardless of this setting, so retries keep working either way.

## Usage Guide

### Enqueuing E-mails

The package exposes a single global helper — `queueMail(Mailable $mailable, string|array $recipients): bool`.

```php
use App\Mail\OrderShipped;

$stored = queueMail(new OrderShipped($order), 'john.doe@example.com');

if (! $stored) {
    // write path when DB insert fails; the exception was already `report()`ed
    return back()->with('warning', 'Your receipt will be sent in a minute.');
}
```

Passing an array of addresses enqueues one row per recipient in a single batch insert:

```php
queueMail(new NewsletterDigest(), [
    'john.doe@example.com',
    'jane.doe@example.com',
    'ops@company.com',
]);
```

`queueMail()` returns `true` on success and `false` on failure. Failures are routed to your configured exception logger via Laravel's `report()` helper — they never interrupt the request cycle.

### Mailable Class Requirements

Any class extending `Illuminate\Mail\Mailable` works. The outbox captures every **public** property, in either style:

```php
// Style 1 — Constructor Property Promotion
namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use Illuminate\Mail\Mailable;

class OrderShipped extends Mailable
{
    public function __construct(
        public Order  $order,
        public User   $customer,
        public string $courier = 'dhl',
    ) {}

    public function build(): self
    {
        return $this->subject('Order #'.$this->order->id.' shipped')
                    ->view('mail.orders.shipped');
    }
}
```

```php
// Style 2 — Public properties assigned after instantiation
class WelcomeBack extends Mailable
{
    public User   $user;
    public string $headline = 'Welcome back';

    public function build(): self
    {
        return $this->subject($this->headline)
                    ->view('mail.welcome-back');
    }
}

// Caller:
$mail = new WelcomeBack();
$mail->user     = $user;
$mail->headline = 'Long time no see!';

queueMail($mail, $user->email);
```

Eloquent model values are reloaded via `findOrFail()` at delivery time — so stale data from the enqueue moment is never used. Scalars, arrays, and plain objects are stored as-is.

### Running the Worker

#### One-off / Debug

```bash
php artisan cron-mail:process
```

Optional override for the batch size:

```bash
php artisan cron-mail:process --limit=20
```

The command prints a summary of how many mails were sent vs. failed.

#### Scheduled via Laravel Scheduler

Register it in `routes/console.php` (or `app/Console/Kernel.php` for older Laravel):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cron-mail:process')
    ->everyMinute()
    ->withoutOverlapping();
```

`withoutOverlapping()` is highly recommended: if one run bleeds into the next minute, Laravel will simply skip the overlap.

#### Worker Selection Criteria

Each run selects rows matching:

- `status = 'pending'` **OR** `(status = 'failed' AND attempts < max_attempts)`,
  ordered by `created_at ASC`, limited to `batch_size` (or `--limit`).

Rows already marked `sent` (see [`delete_after_send`](#delivery-audit-trail-delete_after_send)) are never matched.

The composite index `['status', 'attempts', 'created_at']` in the migration keeps this query cheap even on very large outboxes.

## Testing

The package ships with an isolated PHPUnit suite driven by [Orchestra Testbench](https://packages.tools/testbench). It runs against an in-memory SQLite database by default — never against your application's primary DB.

From the package root (working on the package itself):

```bash
composer install --dev
./vendor/bin/phpunit
```

Expected output:

```
OK (14 tests, 52 assertions)
```

The suite covers:

- single-recipient & multi-recipient enqueue
- correct payload shape for Eloquent models vs. scalars
- fail-safe behaviour when the outbox table is missing
- reconstruction + send + row removal end-to-end
- public-property hydration (verified both present and absent)
- failure marking (`attempts++`, `error_message` populated, `status = 'failed'`)
- `max_attempts` gating
- retry below `max_attempts`
- `batch_size` enforcement
- audit-trail retention with `delete_after_send => false` (`status = 'sent'`, `sent_at` written, `error_message` cleared)
- sent rows are never picked up again on subsequent runs
- default `delete_after_send => true` still deletes

## License

The Laravel Cron Mailer package is open-sourced software licensed under the [MIT License](LICENSE).
