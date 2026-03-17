<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Attendance;
use App\Models\Activity;
use App\Models\ActivityAssignmentTime;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\PatrolPoint;
use App\Models\PatrolScan;
use App\Models\PatrolScanPhoto;
use App\Models\Post;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Team;
use App\Policies\AttendancePolicy;
use App\Policies\ActivityPolicy;
use App\Policies\ActivityAssignmentTimePolicy;
use App\Policies\AssignmentPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PatrolPointPolicy;
use App\Policies\PatrolScanPolicy;
use App\Policies\PatrolScanPhotoPolicy;
use App\Policies\PostPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\SchedulePolicy;
use App\Policies\UserPolicy;
use App\Policies\TeamPolicy;
use Illuminate\Support\Facades\Gate;

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
        // ============ REGISTER POLICIES ============
        // Map each model to its policy
        Gate::policy(Attendance::class, AttendancePolicy::class);
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
    }
}
