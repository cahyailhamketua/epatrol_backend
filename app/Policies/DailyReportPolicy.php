<?php

namespace App\Policies;

use App\Models\DailyReport;
use App\Models\User;

class DailyReportPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            'dev',
            'ho',
            'admin_project',
            'komandan_regu',
        ]);
    }

    public function view(User $user, DailyReport $report): bool
    {
        if ($user->role === 'dev') {
            return true;
        }

        if ($user->role === 'ho') {
            return $user->organization_id ===
                $report->project->organization_id;
        }

        return (int) $user->project_id ===
            (int) $report->project_id;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [
            'dev',
            'ho',
            'admin_project',
            'komandan_regu',
        ]);
    }

    public function update(User $user, DailyReport $report): bool
    {
        return $this->view($user, $report);
    }

    public function delete(User $user, DailyReport $report): bool
    {
        return $this->view($user, $report);
    }

    public function download(User $user, DailyReport $report): bool
    {
        return $this->view($user, $report);
    }

    public function generatePdf(User $user, DailyReport $report): bool
    {
        return $this->view($user, $report);
    }
}