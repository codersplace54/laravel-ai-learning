<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use App\Models\UserServiceApplication;
use App\Models\ApplicationWorkflowAssignment;
use App\Observers\ServiceApplicationStatusChangeObserver;
use App\Observers\ApplicationWorkflowAssignmentObserver;
use App\Models\ServiceMaster;
use App\Models\ServiceQuestionnaire;
use App\Models\ServiceFeeRule;
use App\Models\ServiceApprovalFlow;
use App\Models\RenewalCycle;
use App\Models\RenewalFeeRule;
use App\Observers\Ai\ServiceKnowledgeObserver;

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
        ServiceMaster::observe(
            ServiceKnowledgeObserver::class
        );

        ServiceQuestionnaire::observe(
            ServiceKnowledgeObserver::class
        );

        ServiceFeeRule::observe(
            ServiceKnowledgeObserver::class
        );

        ServiceApprovalFlow::observe(
            ServiceKnowledgeObserver::class
        );

        RenewalCycle::observe(
            ServiceKnowledgeObserver::class
        );

        RenewalFeeRule::observe(
            ServiceKnowledgeObserver::class
        );
    }
}
