<?php

namespace FunnyDev\GoogleDrive;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

class GoogleDriveServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/google-drive.php' => config_path('google-drive.php'),
        ], 'funnydev-google-drive');

        try {
            if (!file_exists(config_path('google-drive.php'))) {
                $this->commands([
                    \Illuminate\Foundation\Console\VendorPublishCommand::class,
                ]);

                Artisan::call('vendor:publish', ['--provider' => 'FunnyDev\\GoogleDrive\\GoogleDriveServiceProvider', '--tag' => ['funnydev-google-drive']]);
            }
        } catch (\Exception $e) {}
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/google-drive.php', 'google-drive'
        );
        $this->app->singleton(\FunnyDev\GoogleDrive\GoogleDriveSdk::class, function () {
            return new \FunnyDev\GoogleDrive\GoogleDriveSdk;
        });
    }
}
