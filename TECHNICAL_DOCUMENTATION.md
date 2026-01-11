# ChocoClear Plugin — Technical Documentation

## Overview

ChocoClear is an October CMS v3 maintenance plugin providing two dashboard widgets for cache inspection and file purging. The plugin addresses a critical performance challenge: storage inspection on large installations (17GB+) can cause timeouts and memory exhaustion. The solution uses Laravel's queue system with fallback to synchronous execution.

## Architecture

### Plugin Structure

```
chococlear/
├── Plugin.php                          # Main plugin registration
├── reportwidgets/
│   ├── ClearCache.php                 # Cache inspection & clearing widget
│   └── PurgeFiles.php                 # File purging widget with background job support
├── classes/
│   ├── SizeHelper.php                 # Utility for size calculation and formatting
│   └── jobs/
│       └── ScanStorageJob.php          # Background job for storage analysis
├── lang/
│   ├── en/lang.php
│   └── nl/lang.php
├── updates/
│   └── version.yaml                    # Version history
└── composer.json
```

### Key Design Decisions

**1. Two-Phase Purge Workflow**
- **Phase 1 (Manual Scan)**: User clicks "Scan" to analyze storage size without blocking dashboard
- **Phase 2 (Purge)**: After inspection, user clicks "Purge" to execute actual file deletion and rescan

This decoupling prevents accidental purges and gives users visibility into storage before destructive operations.

**2. Background Job Architecture**
The `ScanStorageJob` runs in background to prevent timeouts on large storage:
- Dispatched via Laravel queue when `QUEUE_CONNECTION !== 'sync'`
- Falls back to synchronous execution when using `sync` driver (development environments)
- 10-minute timeout per job to handle 17GB+ storage scans
- Automatic retry (2 attempts) if job fails

**3. Cache-Based State Management**
- Widget renders immediately with cached data from last scan
- Scan status tracked in separate cache key for polling
- Eliminates database writes, reducing I/O overhead
- Cache expires automatically (status: 15 min, results: forever until next scan)

**4. Polling Mechanism**
Dashboard uses hidden button with AJAX to poll scan completion:
- Checks scan status every 2 seconds
- Updates widget when status changes to 'completed' or 'failed'
- Preserves October CMS widget context using declarative update pattern

## Implementation Details

### ClearCache Widget (`reportwidgets/ClearCache.php`)

**Purpose**: View and clear CMS/backend caches stored on disk

**Targets** (relative to `storage/`):
- `cms/cache/` — October CMS cache
- `cms/combiner/` — CSS/JS combiner cache
- `cms/twig/` — Twig template cache
- `framework/cache/` — Laravel framework cache

**Operations**:
- `render()` — Displays current cache sizes synchronously (cheap operation)
- `onClear()` — Executes `artisan cache:clear`, updates widget view
- `getSizes()` — Uses `SizeHelper::dirSize()` to calculate directory sizes

**Widget Options**:
- `title` — Widget title (default: "Clear Cache")
- `nochart` — Show compact list view without chart visualization
- `radius` — Chart radius in pixels (default: 200)

**Performance Note**: Cache sizes are calculated on widget render (synchronous) because cache directories are typically small (<500MB). Unlike the Purge widget, no background job needed.

### PurgeFiles Widget (`reportwidgets/PurgeFiles.php`)

**Purpose**: Analyze and purge generated/redundant files from storage

**Key Methods**:
- `render()` — Displays cached scan results and scanning status
- `onScan()` — Initiates background storage analysis via `ScanStorageJob::dispatch()`
- `onCheckStatus()` — AJAX handler for polling scan completion (called every 2 seconds)
- `onClear()` — Executes configured purge operations and rescans storage
- `runScanSynchronously()` — Fallback for `sync` queue driver

**State Management**:
- `CACHE_KEY = 'chococlear.purgefiles.sizes'` — Stores latest scan results and timestamp
- `STATUS_KEY = 'chococlear.purgefiles.scan_status'` — Tracks scan progress ('scanning', 'completed', 'failed', or error array)

**Widget Options**:
- `title` — Widget title (default: "Purge Files")
- `nochart` — Show list view without chart
- `radius` — Chart radius in pixels (default: 200)
- `purge_thumbs` — Delete files matching `^thumb_.*` in uploads/public (default: true)
- `purge_resizer` — Clear `storage/app/resources/resize/` (default: true)
- `purge_uploads` — Delete disk files not in `system_files` table (default: false)
- `purge_orphans` — Delete orphaned `system_files` records with no `attachment_id` (default: false)
- `purge_temp_folder` — Empty `storage/temp/` (default: false)

**AJAX Flow**:
```
User clicks "Scan"
  ↓
onScan() dispatches ScanStorageJob (or runs synchronously)
  ↓
Widget shows "Scanning..." state immediately
  ↓
JavaScript polls onCheckStatus() every 2 seconds
  ↓
Job completes, updates cache with results
  ↓
Poll detects completion, updates widget display
  ↓
User reviews sizes, clicks "Purge"
  ↓
onClear() executes configured operations and rescans
```

### ScanStorageJob (`classes/jobs/ScanStorageJob.php`)

**Purpose**: Background job for expensive storage analysis (prevents timeouts on large storage)

**Configuration**:
- `$timeout = 600` — 10 minutes maximum execution time
- `$tries = 2` — Automatic retry on failure

**Execution Flow**:
1. Marks cache status as 'scanning'
2. Calls `calculateSizes()` to analyze all storage paths
3. Stores results in cache with timestamp
4. Marks status as 'completed' (expires in 1 minute)
5. On error: stores failure status and rethrows exception for queue retry

**Size Calculation** (`calculateSizes()`):

Calculates bytes for each category:

| Category | Path | Method | Notes |
|----------|------|--------|-------|
| **Thumbnails** | `storage/app/uploads/public/` | `dirSize()` with regex filter | Only files matching `^thumb_.*` |
| **Resizer Cache** | `storage/app/resources/resize/` | `dirSize()` | All files |
| **Purgeable Uploads** | `storage/app/uploads/{public,protected}/` | Database query + file check | Files NOT in `system_files.disk_name` |
| **Orphaned Files** | Database `system_files` | Database query + disk check | Records with `attachment_id IS NULL` |
| **Temp Folder** | `storage/temp/` | `dirSize()` | All files |

**Orphaned Files Logic** (`orphanedFilesBytes()`):
- Queries `system_files` with `attachment_id IS NULL` in 1000-row chunks
- For each record, checks if file exists on disk (uses `getDiskPath()` method)
- Sums file sizes, handles permission errors gracefully

**Purgeable Uploads Logic** (`purgeableUploadsBytes()`):
- Scans `/uploads/public` and `/uploads/protected` directories
- Chunks files into 500-file batches to optimize database queries
- Queries `system_files.disk_name` for each batch
- Calculates size of files NOT present in database
- Handles file read errors (unreadable files skipped)

**Error Handling**:
- Catches `Throwable` during size calculations (graceful skip)
- Updates cache status with error message and 5-minute expiry
- Rethrows exception for queue retry mechanism

### SizeHelper (`classes/SizeHelper.php`)

**Purpose**: Utility class for efficient directory size calculation and formatting

**Methods**:

```php
dirSize(
    string $path,
    bool $followSymlinks = false,
    ?string $pattern = null,
    bool $matchOnPath = false
): int
```

- Recursively calculates directory size in bytes
- **Regex filtering**:
  - `$pattern`: Optional regex for filename filtering (e.g., `/^thumb_.*/`)
  - `$matchOnPath`: If true, matches against full path; if false, matches only filename
- **Symlink handling**: By default skips symlinks (safety for circular references)
- **Error handling**: Skips unreadable files without failing
- **Validation**: Validates regex pattern before use (throws `InvalidArgumentException` on invalid regex)

```php
formatSize(int $bytes, int $precision = 2): string
```

- Converts bytes to human-readable format (B, KB, MB, GB, TB, PB, EB, ZB, YB)
- Uses 1024-byte units
- Example: `formatSize(1073741824)` returns `"1.00 GB"`

**Performance Notes**:
- Uses `RecursiveDirectoryIterator` with `SKIP_DOTS` flag for efficiency
- Catches exceptions per-file to prevent cascade failures
- Pattern matching done per-file (minimal overhead)

## Queue System Integration

### Requirements

The plugin requires **Laravel queue configured** in your October CMS installation:

```env
QUEUE_CONNECTION=redis    # or: database, beanstalkd, sqs, etc.
QUEUE_DRIVER=redis        # (alternative naming)
```

### Fallback Behavior

When `QUEUE_CONNECTION=sync`:
- `ScanStorageJob` runs **synchronously** in the same request
- No background processing occurs
- **Warning**: May cause timeout on large storage (17GB+)
- Suitable only for development/testing on small storage

### Queue Worker Setup

For production with background processing:

```bash
# Start queue worker
php artisan queue:work redis --timeout=600 --tries=2

# Or use supervisor for persistent queue worker
# (See Laravel documentation for supervisor configuration)
```

## Cache Key Reference

| Key | Purpose | Expiry | Content |
|-----|---------|--------|---------|
| `chococlear.purgefiles.sizes` | Scan results (sizes, timestamp) | Forever (until next scan) | `['sizes' => [...], 'scanned_at' => DateTime]` |
| `chococlear.purgefiles.scan_status` | Scan progress tracking | 15 minutes | `'scanning'`, `'completed'`, or `['status' => 'failed', 'error' => '...']` |

## Known Issues & Gotchas

### 1. Large Storage Timeouts (Mitigation: Background Job)
**Problem**: Scanning 17GB+ storage synchronously causes PHP timeout/memory exhaustion

**Solution**: Use background job via queue system
- Increases timeout to 10 minutes
- Runs in separate process (isolated memory)
- Non-blocking for dashboard users

**Workaround**: If queue unavailable, ensure sufficient timeout/memory:
```php
// In php.ini or .htaccess
max_execution_time = 600
memory_limit = 512M
```

### 2. Orphaned Files Detection Relies on `attachment_id`
**Current Logic**: Records in `system_files` with `attachment_id IS NULL` are considered orphaned

**Edge Case**: Legitimate files (not attached to models) will be flagged for deletion
- Use with caution in systems with custom file management
- Review "Orphaned Files" size before purging

### 3. Race Conditions During Active Uploads
**Scenario**: File added to disk while scan runs, but not yet in database

**Outcome**: New file size included in "purgeable uploads" count
- Not dangerous (files typically exist in DB quickly)
- Counts may be slightly off during high upload activity

### 4. Symlinks Excluded by Default
**Behavior**: `SizeHelper::dirSize()` skips symlinks to prevent circular traversal

**Implication**: Symlinked storage is not counted in size calculations
- Intentional safety measure
- If symlinks important, modify `dirSize()` call to set `$followSymlinks = true`

### 5. Cached Sizes Can Be Stale
**Behavior**: Widget displays scan results indefinitely until next scan

**Recommendation**: Scan regularly (e.g., weekly) to keep data current
- Add console command if periodic scanning needed
- Or implement automatic rescan on schedule

## Development Notes

### Adding New Purge Target

To add a new file category for purging:

1. **Update `ScanStorageJob::calculateSizes()`**:
   ```php
   $s['newcategory_b'] = SizeHelper::dirSize(storage_path() . '/path/to/files');
   $s['newcategory'] = SizeHelper::formatSize($s['newcategory_b']);
   ```

2. **Update total in `calculateSizes()`**:
   ```php
   $s['all'] = SizeHelper::formatSize(
       $s['thumbs_b'] + $s['resizer_b'] + $s['newcategory_b'] + ...
   );
   ```

3. **Add widget property in `PurgeFiles::defineProperties()`**:
   ```php
   'purge_newcategory' => [
       'title' => 'chocolata.chococlear::lang.plugin.purge_newcategory',
       'type' => 'checkbox',
       'default' => false,
   ]
   ```

4. **Add purge logic in `PurgeFiles::onClear()`**:
   ```php
   if ($this->property('purge_newcategory')) {
       // Execute purge operation
   }
   ```

5. **Add language strings** in `lang/en/lang.php` and `lang/nl/lang.php`

### Modifying Size Calculation

The `SizeHelper::dirSize()` is optimized but flexible:

```php
// Count only files matching pattern
$size = SizeHelper::dirSize(
    storage_path() . '/uploads',
    false,                      // Don't follow symlinks
    '/\.jpg$/',                 // Only .jpg files
    false                       // Match filename only
);
```

### Testing Queue Integration

To test with queue:

```bash
# Start queue worker in another terminal
php artisan queue:work redis --timeout=600

# In browser, click "Scan" - job should process in background
# Check queue logs to verify job execution
```

## Performance Considerations

### Dashboard Load Time
- Widget renders immediately with cached data
- Zero blocking on page load
- Scan initiated asynchronously (user clicks button)

### Storage Scan Performance
- Recursive directory iteration optimized with flags
- Database queries chunked to prevent memory issues
- File stat calls minimized (uses `SplFileInfo` caching)
- Timeout: 10 minutes sufficient for 17GB+ on modern hardware

### Database Impact
- Orphaned files detection uses chunked queries (1000 rows)
- Purgeable uploads uses chunked queries (500 files per batch)
- Minimal SELECT queries, no writes during scan

### Cache Storage
- Scan results cached indefinitely (small data: < 1KB)
- Status cache 15-minute expiry
- No external cache required (file-based cache sufficient)

## Maintenance Checklist

- **Regular Scanning**: Schedule weekly scans to keep storage analysis current
- **Queue Monitoring**: If using background processing, monitor queue worker health
- **Error Logs**: Check Laravel logs for job failures (search `ScanStorageJob`)
- **Version Updates**: Consult `updates/version.yaml` for breaking changes
- **Disk Space**: Ensure sufficient temp space during scan and purge operations

## Future Enhancement Opportunities

1. **Scheduled Scanning**: Automatic periodic scans via console command
2. **Reconciliation**: Manual command to compare Billit invoices with local records
3. **Advanced Filtering**: Custom regex patterns for purge operations
4. **Scan History**: Store previous scan results for trending analysis
5. **Parallel Scanning**: Process multiple directories concurrently (if queue supports)
6. **Incremental Purging**: Purge in batches to reduce single-operation impact

## Version History Summary

| Version | Key Change |
|---------|-----------|
| 1.0.x | Initial releases with basic cache clearing |
| 1.1.0 | Manual scan with caching - prevents dashboard blocking |
| 1.2.0 | **Background job support** - handles large storage (17GB+) |
| 1.2.1+ | Queue driver fallback, AJAX polling, UI/UX improvements |

See `/updates/version.yaml` for complete release notes.
