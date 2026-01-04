<?php

namespace App\Providers;

use Horsefly\Applicant;
use App\Observers\ApplicantObserver;
use Horsefly\Sale;
use App\Observers\SaleObserver;
use Horsefly\Office;
use App\Observers\HeadOfficeObserver;
use Horsefly\Unit;
use App\Observers\UnitObserver;
use Horsefly\User;
use App\Observers\UserObserver;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; 

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the Applicant observer
        Applicant::observe(ApplicantObserver::class);
        Sale::observe(SaleObserver::class);
        Office::observe(HeadOfficeObserver::class);
        Unit::observe(UnitObserver::class);
        User::observe(UserObserver::class);
        Paginator::useBootstrapFive(); // or Paginator::useBootstrapFour();

        DB::listen(function($query) {
            // Log queries slower than 100ms (you can change the threshold)
            if ($query->time > 100) {
                Log::channel('slow_queries')->info('Slow Query Detected', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time' => $query->time . ' ms'
                ]);
            }
        });
    }
}
