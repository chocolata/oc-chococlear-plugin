<?php namespace Chocolata\ChocoClear\ReportWidgets;

use Artisan;
use Backend\Classes\ReportWidgetBase;
use Cache;
use Chocolata\ChocoClear\Classes\SizeHelper;
use Flash;
use Lang;
use System\Models\File as FileModel;

class PurgeFiles extends ReportWidgetBase
{
    const THUMBS_PATH       = '/app/uploads/public';
    const THUMBS_REGEX      = '/^thumb_.*/';
    const RESIZER_PATH      = '/app/resources/resize';
    const TEMP_FOLDER_PATH  = '/temp';
    const UPLOADS_PATH      = '/app/uploads';
    const CACHE_KEY         = 'chococlear.purgefiles.sizes';

    protected $defaultAlias = 'chocolata_purge_files';

    /**
     * Render widget - shows cached data only (no calculations)
     */
    public function render()
    {
        $cached = Cache::get(self::CACHE_KEY);

        $this->vars['size'] = $cached['sizes'] ?? null;
        $this->vars['last_scan'] = $cached['scanned_at'] ?? null;
        $this->vars['radius'] = $this->property('radius');
        $this->vars['widget_id'] = 'purgesizes-' . $this->getId();

        $widget = $this->property('nochart') ? 'widget2' : 'widget';
        return $this->makePartial($widget);
    }

    /**
     * AJAX handler: Scan storage and cache results
     */
    public function onScan()
    {
        $sizes = $this->calculateSizes();
        $scannedAt = now();

        Cache::forever(self::CACHE_KEY, [
            'sizes' => $sizes,
            'scanned_at' => $scannedAt,
        ]);

        $this->vars['size'] = $sizes;
        $this->vars['last_scan'] = $scannedAt;
        $this->vars['radius'] = $this->property('radius');
        $this->vars['widget_id'] = 'purgesizes-' . $this->getId();

        $widget = $this->property('nochart') ? 'widget2' : 'widget';
        return [
            'partial' => $this->makePartial($widget)
        ];
    }

    public function defineProperties()
    {
        return [
            'title' => [
                'title'             => 'backend::lang.dashboard.widget_title_label',
                'default'           => 'Purge Files',
                'type'              => 'string',
                'validationPattern' => '^.+$',
                'validationMessage' => 'backend::lang.dashboard.widget_title_error'
            ],
            'nochart' => [
                'title'             => 'chocolata.chococlear::lang.plugin.nochart',
                'type'              => 'checkbox',
            ],
            'radius' => [
                'title'             => 'chocolata.chococlear::lang.plugin.radius',
                'type'              => 'string',
                'validationPattern' => '^[0-9]+$',
                'validationMessage' => 'Only numbers!',
                'default'           => '200',
            ],
            'purge_thumbs' => [
                'title'             => 'chocolata.chococlear::lang.plugin.purge_thumbs',
                'type'              => 'checkbox',
                'default'           => true,
            ],
            'purge_resizer' => [
                'title'             => 'chocolata.chococlear::lang.plugin.purge_resizer',
                'type'              => 'checkbox',
                'default'           => true,
            ],
            'purge_uploads' => [
                'title'             => 'chocolata.chococlear::lang.plugin.purge_uploads',
                'type'              => 'checkbox',
                'default'           => false,
            ],
            'purge_orphans' => [
                'title'             => 'chocolata.chococlear::lang.plugin.purge_orphans',
                'type'              => 'checkbox',
                'default'           => false,
            ],
            'purge_temp_folder' => [
                'title'             => 'chocolata.chococlear::lang.plugin.purge_temp_folder',
                'type'              => 'checkbox',
                'default'           => false,
            ]
        ];
    }

    /**
     * AJAX handler: Purge files and refresh data
     */
    public function onClear()
    {
        Artisan::call('cache:clear');

        if ($this->property('purge_thumbs')) {
            Artisan::call('october:util', [
                'name' => 'purge thumbs',
                '--force' => true,
                '--no-interaction' => true,
            ]);
        }
        if ($this->property('purge_resizer')) {
            Artisan::call('october:util', [
                'name' => 'purge resizer',
                '--force' => true,
                '--no-interaction' => true,
            ]);
        }
        if ($this->property('purge_uploads')) {
            Artisan::call('october:util', [
                'name' => 'purge uploads',
                '--force' => true,
                '--no-interaction' => true,
            ]);
        }
        if ($this->property('purge_orphans')) {
            Artisan::call('october:util', [
                'name' => 'purge orphans',
                '--force' => true,
                '--no-interaction' => true,
            ]);
        }
        if ($this->property('purge_temp_folder')) {
            $path = storage_path() . self::TEMP_FOLDER_PATH;
            if (\File::isDirectory($path)) {
                \File::cleanDirectory($path);
            }
        }

        // Clear cached sizes after purge
        Cache::forget(self::CACHE_KEY);

        Flash::success(Lang::get('chocolata.chococlear::lang.plugin.success'));

        // Recalculate and return fresh data
        return $this->onScan();
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

        $s['all']         = SizeHelper::formatSize($s['thumbs_b'] + $s['resizer_b'] + $s['temp_folder_b'] + $s['uploads_b'] + $s['orphans_b']);
        return $s;
    }



    private function purgeableUploadsBytes(string $localPath): int
    {
        if (!is_dir($localPath)) {
            return 0;
        }

        $total = 0;

        // Grotere chunks zijn efficiënter; pas aan naar smaak
        $chunks = collect(\File::allFiles($localPath))->chunk(500);

        foreach ($chunks as $chunk) {
            // verzamel bestandsnamen (disk_name)
            $names = [];
            foreach ($chunk as $file) {
                $names[] = $file->getFilename();
            }

            if (!$names) {
                continue;
            }

            // bekende disk_names in DB ophalen en omzetten naar set voor O(1) lookup
            $present = array_flip(
                FileModel::whereIn('disk_name', $names)->pluck('disk_name')->all()
            );

            foreach ($chunk as $file) {
                $name = $file->getFilename();

                if (!isset($present[$name])) {
                    try {
                        $total += $file->getSize();
                    } catch (\Throwable $e) {
                        // onleesbaar / race condition -> overslaan
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
                        // Bepaal disk + pad zoals October het bewaart
                        $disk = $file->disk ?: 'local';
                        $path = method_exists($file, 'getDiskPath') ? $file->getDiskPath() : null;

                        if ($path && \Storage::disk($disk)->exists($path)) {
                            // Neem de DB-kolom file_size (snel) maar tel alleen als het bestand echt bestaat
                            $total += (int) $file->file_size;
                        }
                    } catch (\Throwable $e) {
                        // overslaan bij race conditions / onleesbare disks
                    }
                }
            });

        return $total;
    }

}
