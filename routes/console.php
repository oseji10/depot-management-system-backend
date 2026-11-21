<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled jobs. The server runs `php artisan schedule:run` every minute (one cron line), and Laravel decides from here
 * what is due. See the setup notes for the cron line.
 */

// Daily sales report: checked every 15 minutes, each business gets it once a day after its chosen time.
Schedule::command('reports:daily-sales')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();

// Alerts (low stock, debts, approvals…) worked out and emailed even when nobody has the app open.
Schedule::command('notifications:refresh')->hourly()->withoutOverlapping()->runInBackground();
