<?php namespace Chocolata\ChocoClear\ReportWidgets;

use Artisan;
use Backend\Classes\ReportWidgetBase;
use Cache;
use Chocolata\ChocoClear\Classes\Jobs\ScanStorageJob;
use Flash;
use Lang;

class PurgeFiles extends ReportWidgetBase
{
    const CACHE_KEY  = 'chococlear.purgefiles.sizes';
    const STATUS_KEY = 'chococlear.purgefiles.scan_status';
    const TEMP_FOLDER_PATH = '/temp';

    protected $defaultAlias = 'chocolata_purge_files';

    /**
     * Render widget - shows cached data and scan status
     */
    public function render()
    {
        $cached = Cache::get(self::CACHE_KEY);
        $status = Cache::get(self::STATUS_KEY);

        $this->vars['size'] = $cached['sizes'] ?? null;
        $this->vars['last_scan'] = $cached['scanned_at'] ?? null;
        $this->vars['scanning'] = ($status === 'scanning');
        $this->vars['scan_failed'] = is_array($status) && ($status['status'] ?? null) === 'failed';
        $this->vars['scan_error'] = is_array($status) ? ($status['error'] ?? null) : null;
        $this->vars['radius'] = $this->property('radius');
        $this->vars['widget_id'] = 'purgesizes-' . $this->getId();

        $widget = $this->property('nochart') ? 'widget2' : 'widget';
        return $this->makePartial($widget);
    }

    /**
     * AJAX handler: Start background scan job
     */
    public function onScan()
    {
        // Dispatch job to queue
        ScanStorageJob::dispatch();

        // Mark as scanning immediately
        Cache::put(self::STATUS_KEY, 'scanning', now()->addMinutes(15));

        // Return scanning state
        return $this->returnWidgetState(true);
    }

    /**
     * AJAX handler: Check scan status (for polling)
     */
    public function onCheckStatus()
    {
        $status = Cache::get(self::STATUS_KEY);

        // If completed, clear status so we don't keep returning completed
        if ($status === 'completed') {
            Cache::forget(self::STATUS_KEY);
        }

        $scanning = ($status === 'scanning');

        return array_merge(
            $this->returnWidgetState($scanning),
            ['scanning' => $scanning]
        );
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
     * AJAX handler: Purge files and start background rescan
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
        Cache::forget(self::STATUS_KEY);

        Flash::success(Lang::get('chocolata.chococlear::lang.plugin.success'));

        // Dispatch scan job to recalculate
        ScanStorageJob::dispatch();
        Cache::put(self::STATUS_KEY, 'scanning', now()->addMinutes(15));

        // Return scanning state
        return $this->returnWidgetState(true, true);
    }

    /**
     * Helper to return widget partial with current state
     */
    private function returnWidgetState(bool $scanning = false, bool $clearData = false): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        $status = Cache::get(self::STATUS_KEY);

        $this->vars['size'] = $clearData ? null : ($cached['sizes'] ?? null);
        $this->vars['last_scan'] = $clearData ? null : ($cached['scanned_at'] ?? null);
        $this->vars['scanning'] = $scanning;
        $this->vars['scan_failed'] = is_array($status) && ($status['status'] ?? null) === 'failed';
        $this->vars['scan_error'] = is_array($status) ? ($status['error'] ?? null) : null;
        $this->vars['radius'] = $this->property('radius');
        $this->vars['widget_id'] = 'purgesizes-' . $this->getId();

        $widget = $this->property('nochart') ? 'widget2' : 'widget';
        return [
            '#purgesizes-' . $this->getId() => $this->makePartial($widget)
        ];
    }
}
