<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

// Models
use App\Models\User;
use App\Models\Project;
use App\Models\Assignment;
use App\Models\Schedule;
use App\Models\Attendance;
use App\Models\Post;
use App\Models\Organization;

// Policies
use App\Policies\UserPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\AssignmentPolicy;
use App\Policies\SchedulePolicy;
use App\Policies\AttendancePolicy;
use App\Policies\PostPolicy;
use App\Policies\OrganizationPolicy;

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
        // ========== REGISTER POLICIES ==========
        // Map models to their respective policies
        
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Assignment::class, AssignmentPolicy::class);
        Gate::policy(Schedule::class, SchedulePolicy::class);
        Gate::policy(Attendance::class, AttendancePolicy::class);
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
    }
}

