<?php

namespace Pantera\CronMailer\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Pantera\CronMailer\CronMailerServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Register the package service provider under test.
     */
    protected function getPackageProviders($app): array
    {
        return [
            CronMailerServiceProvider::class,
        ];
    }

    /**
     * Force an isolated in-memory SQLite connection for every test.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('mail.default', 'array');

        $app['view']->addNamespace('cron-mailer-tests', __DIR__ . '/Fixtures/views');
    }

    /**
     * Run the package migration stub plus the fixture users table.
     */
    protected function defineDatabaseMigrations(): void
    {
        $migration = require __DIR__ . '/../database/migrations/create_pending_emails_table.php.stub';
        $migration->up();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });
    }
}
