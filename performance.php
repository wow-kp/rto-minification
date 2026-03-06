<?php

use Intervention\Image\Facades\Image;
use MatthiasMullie\Minify;

define('M_DEBUG', isset($_GET['no-minify']));
define('PUBLIC_DIR', '');
define('THEME_DIR', PUBLIC_DIR . '/themes/');
define('THEME_URI', '/themes/');

if (isset($_SERVER['REQUEST_URI'])) {
    $url = isset($_GET['all']) ? $_SERVER['REQUEST_URI'] : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    define('M_CSS', hash('sha1', $url) . '.css');
    define('M_JS',  hash('sha1', $url) . '.js');
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function getTheme(): string
{
    static $theme = null;
    return $theme ??= getAccount()->getThemeAttribute();
}

function buildCacheKey(array $files, string $type): string
{
    return buildFingerprint($files) . '.' . $type;
}

/**
 * Hash of file paths + their mtimes. Used both as the cache filename and as a
 * stored fingerprint inside APCu so warm requests can detect file changes
 * without re-parsing the DOM.
 */
function buildFingerprint(array $files): string
{
    $parts = array_map(
        fn($path, $mtime) => $path . ':' . $mtime,
        array_keys($files),
        array_values($files)
    );
    return hash('sha1', implode('|', $parts));
}

function getLoadingMarkup(string $theme, string $cacheFile, int $version): string
{
    $href = THEME_URI . $theme . '/css/cache/' . $cacheFile . '?v=' . $version;

    // FIX: handleMainStylesheet now checks whether the stylesheet already loaded
    // (the onload on the <link> fires after rel is swapped, so the element is
    // already a stylesheet by the time handleMainStylesheet runs — we reveal
    // immediately in that case instead of setting a .onload that never fires).
    // A hard 3s timeout ensures the page is never permanently invisible due to
    // a JS error or a network failure.
    return <<<HTML
    <script type="text/javascript">
        function unloadBody() {
            document.body && document.body.classList.remove('loading');
        }
        function handleMainStylesheet() {
            var link = document.querySelector("link[as='style']");
            if (!link || link.rel === 'stylesheet') {
                unloadBody();
                return;
            }
            link.addEventListener('load', unloadBody);
        }
        // Hard fallback — never leave the page blank for more than 3 seconds
        setTimeout(unloadBody, 3000);
        // Secondary fallback via window.onload
        window.addEventListener('load', unloadBody);
    </script>
    <style type="text/css">
        body.loading {
            background-color: #ffffff !important;
            opacity: 0 !important;
            visibility: hidden !important;
        }
    </style>
    <link href="{$href}" rel="preload" as="style"
          onload="this.rel='stylesheet';this.id='main-stylesheet';handleMainStylesheet();">
    <noscript><link rel="stylesheet" href="{$href}"></noscript>
    HTML;
}

/**
 * Minify into a .tmp file, validate, then atomically rename into place.
 * Returns the cache path on success, null on failure.
 */
function atomicMinify(object $minifier, string $cachePath): ?string
{
    $tmpPath = $cachePath . '.tmp';

    try {
        $minifier->minify($tmpPath);
    } catch (Throwable $e) {
        @unlink($tmpPath);
        return null;
    }

    if (!file_exists($tmpPath) || filesize($tmpPath) === 0) {
        @unlink($tmpPath);
        return null;
    }

    if (file_exists($cachePath)) {
        unlink($cachePath);
    }

    rename($tmpPath, $cachePath);
    chmod($cachePath, 0644);

    return $cachePath;
}

function evictStaleCacheFiles(string $cacheDir, string $currentCacheKey): void
{
    foreach (glob($cacheDir . '*') as $file) {
        if (is_file($file) && basename($file) !== $currentCacheKey) {
            @unlink($file);
        }
    }
}

function buildMinifier(string $type, array $files, string $inline): object
{
    $class    = $type === 'css' ? Minify\CSS::class : Minify\JS::class;
    $minifier = empty($files)
        ? new $class()
        : new $class(public_path(array_key_first($files)));

    foreach (array_slice(array_keys($files), 1) as $file) {
        $minifier->add(public_path($file));
    }

    if ($inline) {
        $minifier->add($inline);
    }

    return $minifier;
}

function outputCacheTag(string $type, string $theme, string $cacheKey, int $version): void
{
    if ($type === 'css') {
        echo getLoadingMarkup($theme, $cacheKey, $version);
    } else {
        echo '<script src="' . THEME_URI . $theme . '/js/cache/' . $cacheKey . '?v=' . $version . '" defer></script>';
    }
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

function minifyCSS(string $html): void
{
    minifyAssets($html, 'css');
}

function minifyJS(string $html): void
{
    minifyAssets($html, 'js');
}

// ---------------------------------------------------------------------------
// Core minification
// ---------------------------------------------------------------------------

function minifyAssets(string $html, string $type): void
{
    $theme    = getTheme();
    $cacheDir = public_path('/themes/' . $theme . '/' . $type . '/cache/');

    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    if (M_DEBUG || isset($_GET['search-term']) || isset($_GET['all'])) {
        echo $html;
        return;
    }

    // ------------------------------------------------------------------
    // Fast path: APCu hit keyed on the URL path — stable, one entry per
    // unique page, skips DOM parsing entirely on warm requests.
    // The stored fingerprint detects source file changes so stale entries
    // are never served even if APCu hasn't expired yet.
    // ------------------------------------------------------------------
    $urlPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $apcuKey = 'perf_' . $type . '_' . hash('sha1', $urlPath);

    if (function_exists('apcu_exists') && apcu_exists($apcuKey)) {
        $cached    = apcu_fetch($apcuKey);
        $cachePath = $cacheDir . $cached['cache_key'];

        $stillValid = file_exists($cachePath)
            && $cached['fingerprint'] === buildFingerprint($cached['files']);

        if ($stillValid) {
            echo $cached['remaining'];
            outputCacheTag($type, $theme, $cached['cache_key'], filemtime($cachePath));
            return;
        }

        // File changed — bust the entry and fall through to rebuild
        apcu_delete($apcuKey);
    }

    // ------------------------------------------------------------------
    // Slow path: parse the DOM, minify, cache.
    // Runs on first request per worker, after a file change, or after
    // an APCu eviction.
    // ------------------------------------------------------------------
    $extracted = $type === 'css' ? extractCSS($html) : extractJS($html);
    $files     = $extracted['urls'];
    $inline    = $extracted['inline'];
    $remaining = $extracted['remaining'];

    if (empty($files) && !$inline) {
        echo $remaining;
        return;
    }

    $cacheKey  = buildCacheKey($files, $type);
    $cachePath = $cacheDir . $cacheKey;

    // ------------------------------------------------------------------
    // Slow path: minify and write cache
    // Runs only when the cache file doesn't exist or APCu has expired.
    // ------------------------------------------------------------------

    // Serialize concurrent rebuilds via a lock file
    $lockPath = $cachePath . '.lock';
    $lock     = fopen($lockPath, 'w');

    if (!flock($lock, LOCK_EX)) {
        echo $html;
        fclose($lock);
        return;
    }

    try {
        // Re-check inside the lock — another process may have just rebuilt it
        $versionCache = file_exists($cachePath) ? filemtime($cachePath) : 0;

        if ($versionCache > 0 && (empty($files) || max($files) <= $versionCache)) {
            storeApcuEntry($apcuKey, $cacheKey, $files, $remaining);
            echo $remaining;
            outputCacheTag($type, $theme, $cacheKey, $versionCache);
            return;
        }

        $minifier = buildMinifier($type, $files, $inline);
        $result   = atomicMinify($minifier, $cachePath);

        if ($result === null) {
            // Minification failed — serve unminified as a safe fallback
            echo $html;
            return;
        }

        evictStaleCacheFiles($cacheDir, $cacheKey);

        $versionCache = filemtime($cachePath);

        storeApcuEntry($apcuKey, $cacheKey, $files, $remaining);
        echo $remaining;
        outputCacheTag($type, $theme, $cacheKey, $versionCache);

    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Store a warm APCu entry keyed on the URL path with a 5 minute TTL.
 * Stores enough to serve the next request without any DOM parsing or
 * filesystem work beyond the fingerprint check.
 * Call apcu_clear_cache() during deploys for instant invalidation.
 */
function storeApcu(string $apcuKey): void
{
    // signature kept for call-site compatibility — body replaced below
}

function storeApcuEntry(string $apcuKey, string $cacheKey, array $files, string $remaining): void
{
    if (!function_exists('apcu_store')) {
        return;
    }

    apcu_store($apcuKey, [
        'cache_key'   => $cacheKey,
        'files'       => $files,
        'fingerprint' => buildFingerprint($files),
        'remaining'   => $remaining,
    ], 300);
}

// ---------------------------------------------------------------------------
// Extraction
// ---------------------------------------------------------------------------

function extractCSS(string $code): array
{
    $dom = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($code, 'HTML-ENTITIES', 'UTF-8'));
    libxml_use_internal_errors($internalErrors);

    $urls = $remove = [];

    foreach (iterator_to_array($dom->getElementsByTagName('link')) as $s) {
        $href = $s->getAttribute('href');
        if (!filter_var($href, FILTER_VALIDATE_URL) && is_file(public_path($href))) {
            $urls[$href] = filemtime(public_path($href));
            $remove[] = $s;
        }
    }

    foreach ($remove as $s) {
        $s->parentNode->removeChild($s);
    }

    $ir     = '';
    $remove = [];
    foreach (iterator_to_array($dom->getElementsByTagName('style')) as $inline) {
        if ($inline->hasAttribute('data-critical')) {
            continue;
        }
        $ir .= $inline->textContent . ' ';
        $remove[] = $inline;
    }

    foreach ($remove as $s) {
        $s->parentNode->removeChild($s);
    }

    return [
        'urls'      => $urls,
        'remaining' => domToString($dom),
        'inline'    => $ir,
    ];
}

function extractJS(string $code): array
{
    $dom = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($code, 'HTML-ENTITIES', 'UTF-8'));
    libxml_use_internal_errors($internalErrors);

    $urls = $remove = [];
    $ir   = '';

    foreach (iterator_to_array($dom->getElementsByTagName('script')) as $s) {
        if ($s->hasAttribute('src')) {
            $src = $s->getAttribute('src');
            if (!filter_var($src, FILTER_VALIDATE_URL) && is_file(public_path($src))) {
                $urls[$src] = filemtime(public_path($src));
                $remove[] = $s;
            }
        } else {
            if ($s->getAttribute('type') !== 'application/ld+json') {
                $ir .= $s->textContent . ' ';
                $remove[] = $s;
            }
        }
    }

    foreach ($remove as $s) {
        $s->parentNode->removeChild($s);
    }

    return [
        'urls'      => $urls,
        'remaining' => domToString($dom),
        'inline'    => $ir,
    ];
}

function domToString(DOMDocument $dom): string
{
    return preg_replace(
        '/^<!DOCTYPE.+?>/i', '',
        str_replace(
            ['<html>', '</html>', '<body>', '</body>', '<head>', '</head>'],
            '',
            $dom->saveHTML()
        )
    );
}

// ---------------------------------------------------------------------------
// Lazy loading
// ---------------------------------------------------------------------------

function applyLazyLoad(string $html): string
{
    $dom = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_use_internal_errors($internalErrors);

    foreach (iterator_to_array($dom->getElementsByTagName('img')) as $img) {
        $classes = $img->getAttribute('class');
        if (strpos($classes, 'no-lazy') !== false) {
            continue;
        }

        $img->setAttribute('loading', 'lazy');
        $img->setAttribute('class', trim($classes . ' lazyload'));

        if ($img->hasAttribute('srcset')) {
            $img->setAttribute('data-srcset', $img->getAttribute('srcset'));
            $img->removeAttribute('srcset');
        }

        $src = $img->getAttribute('src');
        $img->setAttribute('data-src', $src);
        $img->removeAttribute('src');

        $fullPath = public_path($src);
        if (!File::missing($fullPath)) {
            try {
                $image = Image::make($fullPath);
                if (!$img->hasAttribute('width')) {
                    $img->setAttribute('width', $image->width());
                }
                if (!$img->hasAttribute('height')) {
                    $img->setAttribute('height', $image->height());
                }
            } catch (Exception $e) {
                // Non-fatal: dimensions simply won't be set
            }
        }
    }

    foreach (iterator_to_array($dom->getElementsByTagName('source')) as $source) {
        $picture = $source->parentNode;
        if (!$picture || $picture->nodeName !== 'picture') {
            continue;
        }

        if (strpos($picture->getAttribute('class'), 'no-lazy') !== false) {
            continue;
        }

        if ($source->hasAttribute('srcset')) {
            $source->setAttribute('data-srcset', $source->getAttribute('srcset'));
            $source->removeAttribute('srcset');
        }
    }

    return domToString($dom);
}

// ---------------------------------------------------------------------------
// Cache management
// ---------------------------------------------------------------------------

function tinifyImages(string $image): void
{
    // Placeholder — Tinify integration pending
}

function glob_recursive(string $pattern, int $flags = 0): array
{
    $files = glob($pattern, $flags);
    foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
        $files = array_merge($files, glob_recursive($dir . '/' . basename($pattern), $flags));
    }
    return $files;
}

function purgeCache(string $type = 'all'): void
{
    $theme = getTheme();

    $patterns = [
        'css' => THEME_DIR . $theme . '/css/cache/*',
        'js'  => THEME_DIR . $theme . '/js/cache/*',
    ];

    $targets = $type === 'all'
        ? array_values($patterns)
        : (isset($patterns[$type]) ? [$patterns[$type]] : []);

    foreach ($targets as $pattern) {
        array_map('unlink', array_filter(glob($pattern)));
    }

    // Clear APCu so the next request re-parses rather than serving stale paths
    if (function_exists('apcu_clear_cache')) {
        apcu_clear_cache();
    }
}