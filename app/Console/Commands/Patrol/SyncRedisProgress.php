<?php

namespace App\Console\Commands\Patrol;

use Illuminate\Console\Command;

class SyncRedisProgress extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'patrol:sync-progress';
    protected $description = 'Sync Redis patrol scan progress to MySQL for durability';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Starting sync...');
        
        $keys = \Illuminate\Support\Facades\Redis::keys('patrol:progress:*');
        $totalSynced = 0;

        if ($keys) {
            foreach ($keys as $key) {
                // Laravel sometimes prefixes keys, but the facade handles it
                // We just need to extract the ID
                if (preg_match('/patrol:progress:(\d+)/', $key, $matches)) {
                    $attId = $matches[1];
                    $count = \Illuminate\Support\Facades\Redis::get($key);

                    if ($count !== null) {
                        \App\Models\Attendance::where('id', $attId)->update(['patrol_scan_count' => (int) $count]);
                        $totalSynced++;
                    }
                }
            }
        }

        $this->info("Synced {$totalSynced} records.");
    }
}
