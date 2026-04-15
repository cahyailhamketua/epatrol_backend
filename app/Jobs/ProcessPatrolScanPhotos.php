<?php

namespace App\Jobs;

use App\Models\PatrolScan;
use App\Models\PatrolScanPhoto;
use App\Services\ImageWebpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessPatrolScanPhotos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public PatrolScan $scan,
        public array $tempPaths
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ImageWebpService $imageService): void
    {
        foreach ($this->tempPaths as $tempPath) {
            if (!Storage::disk('public')->exists($tempPath)) {
                continue;
            }

            $webpPath = $imageService->storeAsWebpFromPath(
                Storage::disk('public')->path($tempPath),
                'patrol-scan-photos',
                80
            );

            if ($webpPath) {
                PatrolScanPhoto::create([
                    'patrol_scan_id' => $this->scan->id,
                    'photo' => $webpPath,
                ]);
                Storage::disk('public')->delete($tempPath);
            }
        }
    }
}
