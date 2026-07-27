<?php

namespace App\Providers;

use App\Models\Absence;
use App\Models\Activity;
use App\Models\ActivityAssignmentTime;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Organization;
use App\Models\PatrolPoint;
use App\Models\PatrolScan;
use App\Models\PatrolScanPhoto;
use App\Models\OvertimeLog;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\PayrollTerBracket;
use App\Models\TeamUser;
use App\Models\Post;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\Team;
use App\Models\User;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\BeritaAcara;
use App\Models\DailyReport;
use App\Observers\AbsenceObserver;
use App\Observers\AssignmentObserver;
use App\Observers\OvertimeLogObserver;
use App\Observers\ScheduleObserver;
use App\Observers\TeamUserObserver;
use App\Observers\UserObserver;
use App\Services\PayrollRefreshService;
use App\Policies\AbsencePolicy;
use App\Policies\ActivityAssignmentTimePolicy;
use App\Policies\ActivityPolicy;
use App\Policies\AssignmentPolicy;
use App\Policies\AttendancePolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PatrolPointPolicy;
use App\Policies\PatrolScanPhotoPolicy;
use App\Policies\PatrolScanPolicy;
use App\Policies\PayrollDetailPolicy;
use App\Policies\PayrollRunPolicy;
use App\Policies\PayrollTerBracketPolicy;
use App\Policies\PostPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\SchedulePolicy;
use App\Policies\TeamPolicy;
use App\Policies\UserPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\DocumentTypePolicy;
use App\Policies\BeritaAcaraPolicy;
use App\Policies\DailyReportPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PayrollRefreshService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Log::info('REGISTER USER OBSERVER');
        \Log::info('APP SERVICE PROVIDER BOOTED');
        // ============ REGISTER POLICIES ============
        // Map each model to its policy
        Gate::policy(Attendance::class, AttendancePolicy::class);
        Gate::policy(Absence::class, AbsencePolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(ActivityAssignmentTime::class, ActivityAssignmentTimePolicy::class);
        Gate::policy(Assignment::class, AssignmentPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(PatrolPoint::class, PatrolPointPolicy::class);
        Gate::policy(PatrolScan::class, PatrolScanPolicy::class);
        Gate::policy(PatrolScanPhoto::class, PatrolScanPhotoPolicy::class);
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Schedule::class, SchedulePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(PayrollRun::class, PayrollRunPolicy::class);
        Gate::policy(PayrollDetail::class, PayrollDetailPolicy::class);
        Gate::policy(PayrollTerBracket::class, PayrollTerBracketPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(DocumentType::class, DocumentTypePolicy::class);
        Gate::policy(BeritaAcara::class, BeritaAcaraPolicy::class);
        Gate::policy(DailyReport::class, DailyReportPolicy::class);

        // ============ REGISTER OBSERVERS ============
        Assignment::observe(AssignmentObserver::class);
        Schedule::observe(ScheduleObserver::class);
        Absence::observe(AbsenceObserver::class);
        OvertimeLog::observe(OvertimeLogObserver::class);
        User::observe(UserObserver::class);
        TeamUser::observe(TeamUserObserver::class);

        $this->app->terminating(function () {
            app(PayrollRefreshService::class)->flushQueuedRefreshes();
        });
    }
}
