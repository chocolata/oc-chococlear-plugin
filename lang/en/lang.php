<?php
return [
    'plugin' => [
        'name'          => 'Clear Cache / Purge Files',
        'description'   => 'Widget for dashboard',
        'label'         => 'File cache',
        'clear'         => 'Clear',
        'success'       => 'Cleared selected files successfully.',
        'nochart'       => 'Show without chart',
        'radius'        => 'Chart size',
        'delthumbs'     => 'Delete thumbs images?',
        'delthumbspath' => 'Path to the folder with thumbs',
        'thumbs_regex'  => 'Regex for thumb file names',
        'purge_thumbs'  => 'Delete thumbnails',
        'purge_resizer' => 'Clear resizer cache',
        'purge_uploads' => 'Clear uploads outside of system_files table',
        'purge_orphans' => 'Clear orphaned files',
        'purge_temp_folder' => 'Clear temp folder',

        // Scan functionality
        'scan'          => 'Scan',
        'rescan'        => 'Rescan storage',
        'last_scan'     => 'Last scan',
        'no_scan_yet'   => 'No scan performed yet. Click "Scan" to analyze storage.',
        'confirm_purge' => 'Are you sure you want to purge these files?',
    ],
];
