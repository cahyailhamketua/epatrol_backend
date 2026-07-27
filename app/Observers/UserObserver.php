<?php

namespace App\Observers;

use App\Models\User;
use App\Services\PayrollRefreshService;

class UserObserver
{
    // public function __construct(
    //     private readonly PayrollRefreshService $payrollRefreshService,
    // ) {}

    public function updated(User $user): void
    {
        $payrollRefreshService = app(PayrollRefreshService::class);
        
        \Log::info('USER OBSERVER FIRED', [
            'user_id' => $user->id,
        ]);
        
        if ($user->wasChanged([
            'nik',
            'bank_name',
            'bank_account',
        ])) {

            \Log::info('SYNC PAYROLL SNAPSHOT', [
                'user_id' => $user->id,
                'nik' => $user->nik,
                'bank_name' => $user->bank_name,
                'bank_account' => $user->bank_account,
            ]);
        
            $payrollRefreshService->syncUserSnapshot($user);
        }

        if (! $user->wasChanged(['active', 'project_id'])) {
            return;
        }

        if ($user->wasChanged('project_id')) {
            $oldProjectId = (int) ($user->getOriginal('project_id') ?? 0);
            if ($oldProjectId > 0) {
                $payrollRefreshService->refreshAllPeriodsForProject($oldProjectId);
            }
        }

        if ($user->project_id) {
            if ($user->wasChanged('active')) {
                $payrollRefreshService->queueRefreshForProjectMonth(
                    (int) $user->project_id,
                    now()->format('Y-m')
                );
            }

            if ($user->wasChanged('project_id')) {
                $payrollRefreshService->refreshAllPeriodsForProject((int) $user->project_id);
            }
        }

    }
}
