<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Services\ImageWebpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessAttendanceSelfie implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Attendance $attendance,
        public string $tempPath
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(ImageWebpService $imageService): void
    {
        if (!Storage::disk('public')->exists($this->tempPath)) {
            return;
        }

        $webpPath = $imageService->storeAsWebpFromPath(
            Storage::disk('public')->path($this->tempPath), 
            'attendances/selfies', 
            80
        );

        if ($webpPath) {
            $this->attendance->update(['selfie_photo_path' => $webpPath]);
            Storage::disk('public')->delete($this->tempPath);
        }
    }
}
