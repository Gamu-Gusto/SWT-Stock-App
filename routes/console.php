<?php

use App\Models\AppNotification;
use App\Models\Reservation;
use App\Models\Stocktake;
use App\Services\Installer;
use App\Services\Inventory\ReservationService;
use App\Services\NotificationService;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\Rules\Password;

Artisan::command('swt:install', function (Installer $installer) {
    $installer->installReferenceData();
    $this->info('Reference data installed (roles, permissions, categories, default locations and brands).');
    if ($installer->isInstalled()) {
        $this->info('An owner account already exists.');

        return 0;
    }
    if ($this->confirm('Create the first owner account now?', true)) {
        return $this->call('swt:create-owner');
    }

    return 0;
})->purpose('Install reference data and optionally create the first owner (no stock data is created)');

Artisan::command('swt:create-owner {--name=} {--email=} {--password=}', function (Installer $installer) {
    $name = $this->option('name') ?: $this->ask('Owner full name');
    $email = $this->option('email') ?: $this->ask('Owner e-mail address');
    $password = $this->option('password') ?: $this->secret('Password (min. 10 characters, letters and numbers)');
    if (! $this->option('password')) {
        $confirm = $this->secret('Confirm password');
        if ($confirm !== $password) {
            $this->error('Passwords do not match.');

            return 1;
        }
    }
    $validator = validator(compact('name', 'email', 'password'), [
        'name' => ['required', 'max:120'],
        'email' => ['required', 'email'],
        'password' => ['required', Password::defaults()],
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    try {
        $owner = $installer->createOwner($name, $email, $password);
    } catch (InvalidArgumentException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->info("Owner account created for {$owner->email}.");

    return 0;
})->purpose('Create an owner (super admin) account');

Artisan::command('swt:expire-reservations', function (ReservationService $reservations) {
    $count = $reservations->expireDue();
    $this->info("{$count} reservation(s) expired.");
})->purpose('Release expired reservations');

Artisan::command('swt:alerts', function (NotificationService $notifications, AppSettings $settings) {
    $hours = (int) $settings->get('notify_reservation_expiry_hours', 4);
    $expiring = Reservation::query()->where('status', 'active')->where('expires_at', '<=', now()->addHours($hours))->get();
    foreach ($expiring as $reservation) {
        $notifications->raise("reservation_expiring:{$reservation->id}", [
            'type' => 'reservation_expiring',
            'severity' => 'warning',
            'title' => "Reservation {$reservation->reservation_no} expires ".$reservation->expires_at->format('d/m/Y H:i'),
            'message' => "{$reservation->customer_name} ({$reservation->customer_phone})",
            'link' => "/reservations/{$reservation->id}",
            'permission' => 'reservations.manage',
        ]);
    }
    // Resolve alerts for reservations that are no longer active.
    AppNotification::query()->where('type', 'reservation_expiring')->whereNull('resolved_at')->get()
        ->each(function ($n) use ($notifications) {
            $id = (int) str_replace('reservation_expiring:', '', $n->dedupe_key);
            if (Reservation::query()->whereKey($id)->where('status', 'active')->doesntExist()) {
                $notifications->resolve($n->dedupe_key);
            }
        });
    // Stocktakes created but not started for more than a day.
    foreach (Stocktake::query()->where('status', Stocktake::DRAFT)->where('created_at', '<', now()->subDay())->get() as $stocktake) {
        $notifications->raise("stocktake:{$stocktake->id}:draft", [
            'type' => 'pending_stocktake',
            'severity' => 'info',
            'title' => "Stocktake {$stocktake->stocktake_no} has not been started",
            'message' => $stocktake->title,
            'link' => "/stocktakes/{$stocktake->id}",
            'permission' => 'stocktake.manage',
        ]);
    }
    Stocktake::query()->where('status', '!=', Stocktake::DRAFT)->pluck('id')
        ->each(fn ($id) => $notifications->resolve("stocktake:{$id}:draft"));
    $this->info('Alerts refreshed.');
})->purpose('Refresh reservation-expiry and stocktake reminders');

// DirectAdmin cron: * * * * * cd /home/USER/domains/stock.superwheeltyres.co.za/app && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('swt:expire-reservations')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('swt:alerts')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('auth:clear-resets')->daily();
