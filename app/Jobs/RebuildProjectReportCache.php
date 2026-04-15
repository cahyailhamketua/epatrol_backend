<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class RebuildProjectReportCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $projectId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Update versioning for project reports
        Cache::forever('project_reports_'.$this->projectId.'_v', time());
        
        // Potential future expansion: Trigger real-time push notification or broadcast
    }
}
