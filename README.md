# performance.php — Change Summary

## Bug Fixes

### `max()` on empty `$files` causes fatal error
The original code called `max($files)` unconditionally. If no local CSS/JS files were extracted, this would throw a fatal error.  
**Fix:** Added `!empty($files)` guard before the `max()` call.

### `max()` cache comparison logic was inverted
`max($files)` returns the most recently modified source file timestamp. The original check `max($files) < $versionCache` served the cache when the source was *newer* than the cache — the opposite of the intended behaviour.  
**Fix:** Changed to `max($files) <= $versionCache` so the cache is served only when all source files are older than or equal to it.

### `$do` flag not reset between images in `applyLazyLoad`
The `$do` flag was initialised once outside the loop. An image with `no-lazy` would set it to `false`, and that value carried over to all subsequent images in the same page, preventing them from being lazy loaded.  
**Fix:** The `no-lazy` check is now evaluated fresh on every iteration.

### `handleMainStylesheet` never fired — page could stay blank indefinitely
The original JavaScript set `stylesheet.onload` inside `handleMainStylesheet()`, but that function was only called from the `<link>` tag's own `onload` — meaning the stylesheet had already finished loading by the time the handler was assigned. The callback never fired. The only remaining reveal path was `window.onload`, which waits for every resource on the page (images, fonts, iframes) before firing, causing a blank white page for the full duration of resource loading. There was also no timeout cap, so any failure could leave the page permanently invisible.  
**Fix:** `handleMainStylesheet` now checks whether the stylesheet has already loaded and reveals immediately if so. A hard 3-second `setTimeout` fallback and a `window.addEventListener('load')` secondary fallback ensure the page is always revealed.

### Live `DOMNodeList` mutation while iterating
In `extractCSS`, the inline `<style>` nodes were removed inside the same loop that iterated them. Mutating a live `DOMNodeList` during iteration causes nodes to be skipped.  
**Fix:** Applied `iterator_to_array()` to snapshot all node lists before any removal throughout `extractCSS`, `extractJS`, and `applyLazyLoad`.

---

## Security

### World-writable file and directory permissions
Cache directories were created with `0777` and cache files with `0777`, making them world-writable — a security risk on any shared or multi-user environment.  
**Fix:** Directories use `0755`, files use `0644`.

---

## Correctness

### `async defer` on the JS cache tag — `async` silently wins
When both `async` and `defer` are present on a `<script>` tag, `async` takes precedence and `defer` is ignored entirely. This breaks execution order for bundled app scripts.  
**Fix:** Changed to `defer` only.

### Critical inline CSS bundled into the async stylesheet — causes FOUC
`extractCSS` stripped every `<style>` block and merged it into the async-loaded cache file. Any above-the-fold critical CSS would be delayed, causing a flash of unstyled content.  
**Fix:** `<style data-critical>` blocks are left in the DOM and rendered synchronously. Only non-critical inline styles are bundled.

### `<picture>` / `<source>` elements ignored in `applyLazyLoad`
The lazy loader only handled `<img>` tags. `<picture>` elements with `<source srcset="...">` were not processed, so their images loaded eagerly regardless.  
**Fix:** `<source>` elements inside `<picture>` tags now have their `srcset` moved to `data-srcset`. The `no-lazy` class on the parent `<picture>` is also respected.

---

## Performance

### `getAccount()->getThemeAttribute()` called on every function invocation
Every function independently resolved the theme, causing repeated calls to `getAccount()` within a single request.  
**Fix:** Introduced `getTheme()` with a `static` cache so the value is resolved once per request.

### `minifyCSS` and `minifyJS` were near-identical duplicates
Both functions contained the same logic with only the minifier class and output tag differing. Any change had to be made twice.  
**Fix:** Both are now thin wrappers around a shared `minifyAssets(string $html, string $type)` function.

### Duplicated loading markup emitted twice in `minifyCSS`
The inline `<script>` + `<style>` + `<link>` block was copy-pasted verbatim for both the cache-hit and cache-miss branches.  
**Fix:** Extracted into a single `getLoadingMarkup()` helper.

### No native lazy loading — JS-only via lazysizes
`applyLazyLoad` relied entirely on the lazysizes JavaScript library, which requires JS to be active and adds script execution overhead.  
**Fix:** `loading="lazy"` is now set on every eligible `<img>` as the primary mechanism. The lazysizes `lazyload` class is retained alongside it for legacy browser fallback.

### Cache stampede under concurrent requests
When the cache expired, every concurrent request simultaneously saw a stale cache and all triggered a rebuild at the same time. This spiked CPU and risked producing a corrupt cache file.  
**Fix:** A `.lock` file serialises concurrent rebuilds via `flock(LOCK_EX)`. The cache freshness check is repeated inside the lock so waiting processes immediately serve the file the first process just built.

### Minification failure writes an empty cache file
If `$minifier->minify()` threw or produced an empty file, the corrupt result was written and served to all subsequent users until `purgeCache()` was called manually.  
**Fix:** `atomicMinify()` writes to a `.tmp` file first, validates it has non-zero size, then `rename()`s it into place atomically. On failure the previous cache file is untouched and unminified HTML is served as a fallback.

### Old cache files accumulated forever
There was no automatic eviction. With a URL-based cache key, a unique file was written for every distinct URL and never cleaned up.  
**Fix:** After a successful rebuild, `evictStaleCacheFiles()` deletes all other files in the cache directory that don't match the new key.

---

## Caching Strategy

### URL-based cache key caused redundant files and incorrect invalidation
The original `M_CSS` / `M_JS` constants were hashed from the request URL. Different pages with identical asset sets each got their own redundant cache file. A URL change (e.g. a query string) would bust the cache even if no assets changed.  
**Fix:** The cache filename is now derived from a `sha1` hash of the actual asset file paths and their `filemtime` values. Identical asset sets share one cache file across all pages. A changed source file automatically produces a new key.

### No in-memory caching — DOM parsing ran on every request
Even on a warm cache hit, the entire page HTML was parsed into a `DOMDocument`, traversed, mutated, and serialised back to a string on every request. On a large page this amounts to 5–20ms of CPU per hit.  
**Fix:** APCu is used as an in-memory layer. The APCu key is derived from the URL path (one stable entry per page). Each entry stores the cache key, the asset file list with mtimes, a pre-computed fingerprint, and the stripped `$remaining` HTML. On a warm hit, the entry is fetched and the fingerprint is recomputed and compared — if no files have changed, the response is served with no DOM parsing, no `flock`, and no disk I/O beyond the fingerprint check. If a file has changed, the entry is busted and the slow path rebuilds it. The TTL is 300 seconds; calling `apcu_clear_cache()` during a deploy provides instant invalidation.

### `purgeCache` did not clear APCu
A manual cache purge deleted the minified files on disk but left stale APCu entries pointing at the now-deleted paths, causing the fast path to serve missing files until TTL expiry.  
**Fix:** `purgeCache()` now calls `apcu_clear_cache()` after removing files from disk.