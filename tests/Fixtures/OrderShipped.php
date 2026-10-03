<?php

namespace BagoesPantera\CronMailer\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderShipped extends Mailable
{
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $orderNumber,
    ) {}

    public function build(): self
    {
        return $this->subject('Order '.$this->orderNumber.' shipped')
            ->view('cron-mailer-tests::order-shipped');
    }
}