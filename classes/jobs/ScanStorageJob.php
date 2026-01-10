<?php namespace Chocolata\ChocoClear\Classes\Jobs;

use Cache;
use Chocolata\ChocoClear\Classes\SizeHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use System\Models\File as FileModel;

class ScanStorageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const THUMBS_PATH       = '/app/uploads/public';
    const THUMBS_REGEX      = '/^thumb_.*/';
    const RESIZER_PATH      = '/app/resources/resize';
    const TEMP_FOLDER_PATH  = '/temp';
    const UPLOADS_PATH      = '/app/uploads';
    const CACHE_KEY         = 'chococlear.purgefiles.sizes';
    const STATUS_KEY        = 'chococlear.purgefiles.scan_status';

    /**
     * Job timeout (10 minutes for large storage)
     */
    public $timeout = 600;

    /**
     * Number of retry attempts
     */
    public $tries = 2;

    /**
     * Execute the job
     */
    public function handle()
    {
        try {
            // Mark as scanning
            Cache::put(self::STATUS_KEY, 'scanning', now()->addMinutes(15));

            // Calculate sizes
            $sizes = $this->calculateSizes();

            // Store results
            Cache::forever(self::CACHE_KEY, [
                'sizes' => $sizes,
                'scanned_at' => now(),
            ]);

            // Mark as complete
            Cache::put(self::STATUS_KEY, 'completed', now()->addMinutes(1));

        } catch (\Throwable $e) {
            // Mark as failed with error message
            Cache::put(self::STATUS_KEY, [
                'status' => 'failed',
                'error' => $e->getMessage()
            ], now()->addMinutes(5));

            throw $e;
        }
    }

    /**
     * Calculate all storage sizes (expensive operation)
     */
    private function calculateSizes()
    {
        $s['thumbs_b'] = SizeHelper::dirSize(
            storage_path() . self::THUMBS_PATH,
            false,
            self::THUMBS_REGEX,
            false
        );
        $s['thumbs']        = SizeHelper::formatSize($s['thumbs_b']);
        $s['resizer_b']     = SizeHelper::dirSize(storage_path() . self::RESIZER_PATH);
        $s['resizer']       = SizeHelper::formatSize($s['resizer_b']);
        $s['uploads_b']     = $this->purgeableUploadsBytes((storage_path() . self::UPLOADS_PATH).'/public') +
                              $this->purgeableUploadsBytes((storage_path() . self::UPLOADS_PATH).'/protected');
        $s['uploads']       = SizeHelper::formatSize($s['uploads_b']);
        $s['orphans_b']     = $this->orphanedFilesBytes();
        $s['orphans']       = SizeHelper::formatSize($s['orphans_b']);
        $s['temp_folder_b'] = SizeHelper::dirSize(storage_path() . self::TEMP_FOLDER_PATH);
        $s['temp_folder']   = SizeHelper::formatSize($s['temp_folder_b']);

        $s['all'] = SizeHelper::formatSize(
            $s['thumbs_b'] + $s['resizer_b'] + $s['temp_folder_b'] + $s['uploads_b'] + $s['orphans_b']
        );
        return $s;
    }

    private function purgeableUploadsBytes(string $localPath): int
    {
        if (!is_dir($localPath)) {
            return 0;
        }

        $total = 0;
        $chunks = collect(\File::allFiles($localPath))->chunk(500);

        foreach ($chunks as $chunk) {
            $names = [];
            foreach ($chunk as $file) {
                $names[] = $file->getFilename();
            }

            if (!$names) {
                continue;
            }

            $present = array_flip(
                FileModel::whereIn('disk_name', $names)->pluck('disk_name')->all()
            );

            foreach ($chunk as $file) {
                $name = $file->getFilename();

                if (!isset($present[$name])) {
                    try {
                        $total += $file->getSize();
                    } catch (\Throwable $e) {
                        // Skip unreadable files
                    }
                }
            }
        }

        return $total;
    }

    private function orphanedFilesBytes(): int
    {
        $total = 0;

        FileModel::whereNull('attachment_id')
            ->chunkById(1000, function ($files) use (&$total) {
                foreach ($files as $file) {
                    try {
                        $disk = $file->disk ?: 'local';
                        $path = method_exists($file, 'getDiskPath') ? $file->getDiskPath() : null;

                        if ($path && \Storage::disk($disk)->exists($path)) {
                            $total += (int) $file->file_size;
                        }
                    } catch (\Throwable $e) {
                        // Skip on errors
                    }
                }
            });

        return $total;
    }
}
