<?php

/*
|--------------------------------------------------------------------------
| Backups (owner request, 2026-10-02)
|--------------------------------------------------------------------------
|
| Every night the whole database and all uploaded files (storage/app/private
| and storage/app/public) are written to one encrypted backup file. Backups,
| their history and pending requests live in files under `path`, never in
| the database, so they survive a restore. Keep `path` on a different disk
| from the application when the server has one, and set `copy_path` to a
| second location (a network share or another drive) for a second copy.
|
| The passphrase encrypts every backup. Without it no backup can be
| restored: keep a copy in a sealed envelope or a safe, away from the server.
| Backups are refused while it is missing.
|
*/

return [
    'path' => env('BACKUP_PATH') ?: storage_path('backups'),

    // A second folder that receives a copy of every backup (optional).
    'copy_path' => env('BACKUP_COPY_PATH') ?: null,

    'passphrase' => env('BACKUP_PASSPHRASE'),

    // Nightly backup time, in the institution's timezone (HH:MM).
    'daily_at' => env('BACKUP_DAILY_AT', '01:00'),

    // Weekly restore test (day 0 = Sunday), in the institution's timezone.
    'verify_day' => (int) env('BACKUP_VERIFY_DAY', 0),
    'verify_at' => env('BACKUP_VERIFY_AT', '03:00'),

    // How many backups of each kind are kept. The nightly backup of the 1st of
    // a month is "monthly", of a Sunday "weekly", otherwise "daily".
    'keep' => [
        'daily' => (int) env('BACKUP_KEEP_DAILY', 14),
        'weekly' => (int) env('BACKUP_KEEP_WEEKLY', 8),
        'monthly' => (int) env('BACKUP_KEEP_MONTHLY', 12),
        'manual' => (int) env('BACKUP_KEEP_MANUAL', 10),
        'before-restore' => (int) env('BACKUP_KEEP_BEFORE_RESTORE', 5),
    ],

    // The Backups page warns when the last good backup is older than this.
    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),

    // Folders backed up and restored, by name inside the backup.
    'folders' => [
        'private' => storage_path('app/private'),
        'public' => storage_path('app/public'),
    ],

    // Database client programs (full paths when they are not on the PATH,
    // e.g. C:\xampp\mysql\bin\mysqldump.exe).
    'mysqldump' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL_PATH', 'mysql'),

    // Scratch database for the weekly restore test; created and dropped each
    // time (the database user needs CREATE and DROP for it).
    'verify_database' => env('BACKUP_VERIFY_DATABASE') ?: null,
];
