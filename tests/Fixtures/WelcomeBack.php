<?php

namespace BagoesPantera\CronMailer\Tests\Fixtures;

use Illuminate\Mail\Mailable;

/**
 * Mailable that relies on a public property assigned after instantiation,
 * exercising the hydration pass in the worker command.
 */
class WelcomeBack extends Mailable
{
    public User $user;

    public string $headline = 'untouched';

    public function build(): self
    {
        return $this->subject('Welcome back')
            ->view('cron-mailer-tests::welcome-back');
    }
}