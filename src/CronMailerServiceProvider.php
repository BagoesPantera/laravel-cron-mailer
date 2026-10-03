<?php

namespace Pantera\CronMailer;

use Illuminate\Support\ServiceProvider;
use Pantera\CronMailer\Console\ProcessPendingEmailsCommand;

class CronMailerServiceProvider extends ServiceProvider
{
    /**
     * Register the package configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cron-mailer.php', 'cron-mailer');
    }

    /**
     * Bootstrap the package resources.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cron-mailer.php' => config_path('cron-mailer.php'),
            ], 'cron-mailer-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_pending_emails_table.php.stub' => $this->migrationPath(),
            ], 'cron-mailer-migrations');

            $this->commands([
                ProcessPendingEmailsCommand::class,
            ]);
        }
    }

    /**
     * Resolve the destination path of the published migration.
     */
    protected function migrationPath(): string
    {
        return database_path('migrations/'.date('Y_m_d_His').'_create_pending_emails_table.php');
    }
}