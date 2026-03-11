<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use App\Models\UserServiceApplication;
use App\Models\ApplicationWorkflowAssignment;
use App\Observers\ServiceApplicationStatusChangeObserver;
use App\Observers\ApplicationWorkflowAssignmentObserver;

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
       Schema::defaultStringLength(191);
       UserServiceApplication::observe(ServiceApplicationStatusChangeObserver::class);
    //    ApplicationWorkflowAssignment::observe(ApplicationWorkflowAssignmentObserver::class);
    }
}
