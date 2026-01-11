# ChocoClear Plugin for October CMS

Dashboard widgets to **inspect** and **clean up** cache and storage files.

> **Warning:** Purging files is destructive and cannot be undone.

---

## Widget 1 — Clear Cache

View and clear CMS/backend caches (file cache driver).

**Clears:**
- `cms/cache/`, `cms/combiner/`, `cms/twig/`
- `framework/cache/`

---

## Widget 2 — Purge Files

Scan storage and purge generated/redundant files. Supports **background processing** for large storage (17GB+).

**Workflow:** Click **Scan** → Review sizes → Click **Clear**

**Targets:**
- **Thumbnails** — files matching `^thumb_.*` in uploads
- **Resizer cache** — `storage/app/resources/resize/`
- **Purgeable uploads** — disk files not in `system_files` table
- **Orphaned files** — `system_files` records without `attachment_id`
- **Temp folder** — `storage/temp/`

---

## Queue Setup (Recommended)

For large storage, configure a real queue driver to prevent timeouts:

```env
QUEUE_CONNECTION=database
```

```bash
php artisan queue:work --timeout=600
```

Without a queue worker, scans run synchronously (may timeout on large storage).

---

## Widget Options

Both widgets support:
- **Show without chart** — compact list view
- **Chart size** — radius in pixels (default: 200)

Purge widget has toggles for each target (thumbnails, resizer, uploads, orphans, temp).

---

## Locales

- English (`en`)
- Dutch (`nl`)
