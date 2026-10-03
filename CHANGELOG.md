# Changelog

All notable changes to `bagoespantera/laravel-cron-mailer` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-10-03

### Added
- Config option `delete_after_send` (default: `true`) in `config/cron-mailer.php` to allow keeping sent email records for audit trail purposes.
- `sent` status option in the `pending_emails` table status column enum (`'pending'`, `'sent'`, `'failed'`).
- `sent_at` nullable timestamp column in `pending_emails` table to record exact email delivery time when `delete_after_send` is set to `false`.
- Feature unit tests verifying `delete_after_send = false` behavior and timestamp persistence.

### Changed
- Refactored `ProcessPendingEmailsCommand` to either delete successful records or mark them as `sent` with `sent_at` timestamp based on configuration.
- Updated `README.md` to document the new `delete_after_send` configuration option and migration changes.

## [1.0.0] - 2026-10-03

### Added
- Initial release of Laravel Cron Mailer.
- Zero queue worker setup running via Laravel Scheduler.
- `queueMail()` global helper function with fail-safe database write wrapping.
- Automatic serialization via Reflection for Mailable constructors and public properties (supports Eloquent models & scalar types).
- Isolated error handling and automatic retry attempts.
- Support for Laravel 10, 11, 12, and 13.