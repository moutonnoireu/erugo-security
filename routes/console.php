<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\cleanExpiredShares;
use App\Jobs\maintainDb;
use App\Jobs\sendExpiryWarningEmails;
use App\Jobs\sendExpiredWarningEmails;
use App\Jobs\sendDeletionWarningEmails;
use App\Jobs\pruneLogs;
use App\Jobs\updateLegacySharePaths;
use App\Jobs\backUpDatabase;
use App\Services\SettingsService;

//daily jobs
Schedule::job(cleanExpiredShares::class)->daily();
Schedule::job(sendExpiryWarningEmails::class)->daily();
Schedule::job(sendDeletionWarningEmails::class)->daily();
Schedule::job(maintainDb::class)->daily();
Schedule::job(pruneLogs::class)->daily();
Schedule::job(backUpDatabase::class)->daily();
//hourly jobs
Schedule::job(sendExpiredWarningEmails::class)->hourly();
//manually run jobs
Artisan::command('clean-expired-shares', function () {
    cleanExpiredShares::dispatch();
})->purpose('Clean expired shares');

Artisan::command('send-expiry-warning', function () {
    sendExpiryWarningEmails::dispatch();
})->purpose('Send expiry warning emails');

Artisan::command('send-expired-warning', function () {
    sendExpiredWarningEmails::dispatch();
})->purpose('Send expired warning emails');

Artisan::command('send-deletion-warning', function () {
    sendDeletionWarningEmails::dispatch();
})->purpose('Send deletion warning emails');

Artisan::command('maintain-db', function () {
    maintainDb::dispatch();
})->purpose('Maintain the database');

Artisan::command('prune-logs', function () {
    pruneLogs::dispatch();
})->purpose('Prune logs');

Artisan::command('update-legacy-share-paths', function () {
    updateLegacySharePaths::dispatch();
})->purpose('Update legacy share paths');

Artisan::command('back-up-database', function () {
    backUpDatabase::dispatch();
})->purpose('Back up the database');

Artisan::command('clear-settings-cache', function () {
    app(SettingsService::class)->clearCache();
    $this->info('Settings cache cleared.');
})->purpose('Clear the settings cache');

// Recovery: turn the sign-in IP allowlist off (e.g. after an IP change locked admins out).
// docker compose exec app php artisan disable-ip-allowlist
Artisan::command('disable-ip-allowlist', function () {
    \App\Models\Setting::where('key', \App\Services\IpAllowlist::SETTING_ENABLED)->update(['value' => 'false']);
    app(SettingsService::class)->clearCache();
    $this->info('Sign-in IP allowlist disabled.');
})->purpose('Disable the sign-in IP allowlist');

// Recovery: remove every second factor of a user who lost their device.
// docker compose exec app php artisan reset-2fa user@example.com
Artisan::command('reset-2fa {email}', function (string $email) {
    $user = \App\Models\User::where('email', $email)->first();
    if (!$user) {
        $this->error('No user with this email.');
        return 1;
    }
    app(\App\Services\TwoFactor::class)->disableAll($user);
    $this->info('Two-factor authentication removed for ' . $email . '.');
    return 0;
})->purpose('Remove every second factor (authenticator app, passkeys, recovery codes) of a user');

// ----- encryption at rest of shared files -----

Artisan::command('generate-storage-key', function () {
    $this->line(\App\Services\StorageEncryption::generateKey());
    $this->comment('Set it as STORAGE_ENCRYPTION_KEY and keep a copy outside the server: without it, encrypted files cannot be read.');
})->purpose('Generate a key for STORAGE_ENCRYPTION_KEY');

$shareFiles = function (): \Generator {
    $root = storage_path('app/shares');
    if (!is_dir($root)) {
        return;
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && !str_ends_with($file->getFilename(), '-tmp')) {
            yield $file->getPathname();
        }
    }
};

Artisan::command('encrypt-existing-files', function () use ($shareFiles) {
    $encryption = app(\App\Services\StorageEncryption::class);
    if (!$encryption->hasKey()) {
        $this->error('STORAGE_ENCRYPTION_KEY is missing or invalid.');
        return 1;
    }
    $done = 0;
    $failed = 0;
    foreach ($shareFiles() as $path) {
        if (\App\Services\StorageEncryption::isEncrypted($path)) {
            continue;
        }
        try {
            $encryption->encryptFile($path);
            $done++;
        } catch (\Throwable $e) {
            $failed++;
            $this->error($path . ': ' . $e->getMessage());
        }
    }
    $this->info("Encrypted {$done} file(s)" . ($failed ? ", {$failed} failed." : '.'));
    return $failed ? 1 : 0;
})->purpose('Encrypt the shared files that are still stored in plain text');

Artisan::command('decrypt-files', function () use ($shareFiles) {
    $encryption = app(\App\Services\StorageEncryption::class);
    if (!$encryption->hasKey()) {
        $this->error('STORAGE_ENCRYPTION_KEY is missing or invalid.');
        return 1;
    }
    $done = 0;
    $failed = 0;
    foreach ($shareFiles() as $path) {
        if (!\App\Services\StorageEncryption::isEncrypted($path)) {
            continue;
        }
        try {
            $encryption->decryptFile($path);
            $done++;
        } catch (\Throwable $e) {
            $failed++;
            $this->error($path . ': ' . $e->getMessage());
        }
    }
    $this->info("Decrypted {$done} file(s)" . ($failed ? ", {$failed} failed." : '.'));
    return $failed ? 1 : 0;
})->purpose('Decrypt every encrypted shared file (before removing STORAGE_ENCRYPTION_KEY)');
