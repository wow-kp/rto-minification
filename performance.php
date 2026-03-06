<?php

use Intervention\Image\Facades\Image;
use MatthiasMullie\Minify;

define('M_DEBUG', isset($_GET['no-minify']));
define('PUBLIC_DIR', '');
define('THEME_DIR', PUBLIC_DIR . '/themes/');
define('THEME_URI', '/themes/');

if (isset($_SERVER['REQUEST_URI'])) {
    $url = isset($_GET['all']) ? $_SERVER['REQUEST_URI'] : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    define('M_CSS', hash('md5', $url) . '.css');
    define('M_JS', hash('md5', $url) . '.js');
}

function minifyCSS($html)
{
    $theme = getAccount()->getThemeAttribute();
    $CSS_MIN = public_path('/themes/' . $theme . '/css/cache/' . M_CSS);

    if (!is_dir(public_path('/themes/' . $theme . '/css/cache/'))) {
        mkdir(public_path('/themes/' . $theme . '/css/cache/'), 0o777, true);
    }

    if (M_DEBUG || isset($_GET['search-term']) || isset($_GET['all'])) {
        echo $html;

        return;
    }
    if (!file_exists($CSS_MIN)) {
        touch($CSS_MIN);
        chmod($CSS_MIN, 0o777);
        $versionCache = 0;
    } else {
        $versionCache = filemtime($CSS_MIN);
    }

    $extracted = extractCSS($html);
    $files = $extracted['urls'];
    $inline = $extracted['inline'];
    $remaining = $extracted['remaining'];

    if (max($files) < $versionCache) {
        echo $remaining;
        echo '
		<script type="text/javascript">
			function unloadBody() {
				if (document.body == null) {
					setTimeout(unloadBody,100);
				} else {
					document.body.classList.remove(\'loading\');
				}
			}
			function handleMainStylesheet () {
				const stylesheet = document.querySelector("#main-stylesheet");
				stylesheet.onload = () => {
					unloadBody();
				};
			}
			window.onload = (event) => {
				unloadBody();
			}
		</script>
		<style type="text/css">
		body.loading {
			background-color: #ffffff !important;
			opacity: 0 !important;
			visibility: hidden !important;
		}
		</style>
		<link href="' . THEME_URI . $theme . '/css/cache/' . M_CSS . '?v=' . $versionCache . '" rel="preload" as="style" onload="this.rel=\'stylesheet\';this.setAttribute(\'id\',\'main-stylesheet\'); handleMainStylesheet();">
		<noscript><link rel="stylesheet" href="' . THEME_URI . $theme . '/css/cache/' . M_CSS . '?v=' . $versionCache . '"></noscript>';

        return;
    }

    $files = array_keys($files);

    $minifier = new Minify\CSS(public_path($files[0]));

    foreach ($files as $k => $file) {
        if ($k > 0) {
            $minifier->add(public_path($file));
        }
    }
    if ($inline) {
        $minifier->add($inline);
    }

    $minifier->minify($CSS_MIN);

    echo $remaining;
    echo '
	<script type="text/javascript">
		function unloadBody() {
			if (document.body == null) {
				setTimeout(unloadBody,100);
			} else {
				document.body.classList.remove(\'loading\');
			}
		}
		function handleMainStylesheet () {
			const stylesheet = document.querySelector("#main-stylesheet");
			stylesheet.onload = () => {
				unloadBody();
			};
		}
		window.onload = (event) => {
			unloadBody();
		}
	</script>
	<style type="text/css">
	body.loading {
		background-color: #ffffff;
		opacity: 0;
		visibility: hidden;
	}
	</style>
	<link href="' . THEME_URI . $theme . '/css/cache/' . M_CSS . '?v=' . $versionCache . '" rel="preload" as="style" onload="this.rel=\'stylesheet\';this.setAttribute(\'id\',\'main-stylesheet\'); handleMainStylesheet();">
	<noscript><link rel="stylesheet" href="' . THEME_URI . $theme . '/css/cache/' . M_CSS . '?v=' . $versionCache . '"></noscript>';
}

function extractCSS(string $code)
{
    $dom = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($code, 'HTML-ENTITIES', 'UTF-8'));
    libxml_use_internal_errors($internalErrors);

    $urls = $remove = [];
    $ss = $dom->getElementsByTagName('link');
    foreach ($ss as $s) {
        $href = $s->getAttribute('href');

        if (!filter_var($href, FILTER_VALIDATE_URL) && is_file(public_path($href))) {
            $urls[$href] = filemtime(public_path($href));
            $remove[] = $s;
        }
    }

    foreach ($remove as $s) {
        $s->parentNode->removeChild($s);
    }

    $inlines = $dom->getElementsByTagName('style');
    $ir = '';
    foreach ($inlines as $inline) {
        $ir .= $inline->textContent . ' ';
        $inline->parentNode->removeChild($inline);
    }

    $remaining = preg_replace('/^<!DOCTYPE.+?>/', '', str_replace(['<html>', '</html>', '<body>', '</body>', '<head>', '</head>'], ['', '', '', '', '', ''], $dom->saveHTML()));

    return [
        'urls' => $urls,
        'remaining' => $remaining,
        'inline' => $ir,
    ];
}

function minifyJS($html)
{
    $theme = getAccount()->getThemeAttribute();
    $JS_MIN = public_path('/themes/' . $theme . '/js/cache/' . M_JS);

    if (!is_dir(public_path('/themes/' . $theme . '/js/cache/'))) {
        mkdir(public_path('/themes/' . $theme . '/js/cache/'), 0o777, true);
    }

    if (M_DEBUG || isset($_GET['search-term']) || isset($_GET['all'])) {
        echo $html;

        return;
    }
    if (!file_exists($JS_MIN)) {
        touch($JS_MIN);
        chmod($JS_MIN, 0o777);
        $versionCache = 0;
    } else {
        $versionCache = filemtime($JS_MIN);
    }
    $extracted = extractJS($html);
    $files = $extracted['urls'];
    $inline = $extracted['inline'];
    $remaining = $extracted['remaining'];

    if (max($files) < $versionCache) {
        echo $remaining;
        echo '<script src="' . THEME_URI . $theme . '/js/cache/' . M_JS . '?v=' . $versionCache . '" async defer></script>';

        return;
    }

    $files = array_keys($files);

    $minifier = new Minify\JS(public_path($files[0]));

    foreach ($files as $k => $file) {
        if ($k > 0) {
            $minifier->add(public_path($file));
        }
    }
    if ($inline) {
        $minifier->add($inline);
    }

    $minifier->minify($JS_MIN);

    echo $remaining;
    echo '<script src="' . THEME_URI . $theme . '/js/cache/' . M_JS . '?v=' . $versionCache . '" async defer></script>';
}

function extractJS(string $code)
{
    $dom = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($code, 'HTML-ENTITIES', 'UTF-8'));
    libxml_use_internal_errors($internalErrors);

    $urls = $remove = [];
    $ir = '';
    $ss = $dom->getElementsByTagName('script');
    foreach ($ss as $s) {
        if ($s->hasAttribute('src')) {
            $src = $s->getAttribute('src');
            if (!filter_var($src, FILTER_VALIDATE_URL) && is_file(public_path($src))) {
                $urls[$src] = filemtime(public_path($src));
                $remove[] = $s;
            }
        } else {
            if ($s->hasAttribute('type')) {
                $type = $s->getAttribute('type');
                if ($type != 'application/ld+json') {
                    $ir .= $s->textContent . ' ';
                    $remove[] = $s;
                }
            } else {
                $ir .= $s->textContent . ' ';
                $remove[] = $s;
            }
        }
    }

    foreach ($remove as $s) {
        $s->parentNode->removeChild($s);
    }

    $remaining = preg_replace('/^<!DOCTYPE.+?>/', '', str_replace(['<html>', '</html>', '<body>', '</body>', '<head>', '</head>'], ['', '', '', '', '', ''], $dom->saveHTML()));

    return [
        'urls' => $urls,
        'remaining' => $remaining,
        'inline' => $ir,
    ];
}

function applyLazyLoad(string $html)
{
    $theme = getAccount()->getThemeAttribute();

    $dom = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_use_internal_errors($internalErrors);

    $do = true;
    $images = $dom->getElementsByTagName('img');

    foreach ($images as $img) {
        if ($img->hasAttribute('class')) {
            $do = strpos($img->getAttribute('class'), 'no-lazy') === false ? true : false;
        }

        if ($do) {
            $classes = '';
            if ($img->hasAttribute('class')) {
                $classes = $img->getAttribute('class') . ' ';
            }
            $img->setAttribute('class', $classes . 'lazyload');

            if ($img->hasAttribute('srcset')) {
                $srcset = $img->getAttribute('srcset');
                $img->setAttribute('data-srcset', $srcset);
                $img->removeAttribute('srcset');
            }

            $src = $img->getAttribute('src');
            $img->setAttribute('data-src', $src);
            $img->removeAttribute('src');

            $full_path = public_path($src);
            if (!File::missing($full_path)) {
                try {
                    $image = Image::make($full_path);
                    if (!$img->hasAttribute('width')) {
                        $img->setAttribute('width', $image->width());
                    }
                    if (!$img->hasAttribute('height')) {
                        $img->setAttribute('height', $image->height());
                    }
                } catch (Exception $e) {
                }
            }
        }
    }

    return preg_replace('/^<!DOCTYPE.+?>/', '', str_replace(['<html>', '</html>', '<body>', '</body>', '<head>', '</head>'], ['', '', '', '', '', ''], $dom->saveHTML()));
}

function tinifyImages($image)
{
    //$result = Tinify::fromFile($image);
    //
    //return $result->toFile($image);
}

function glob_recursive($pattern, $flags = 0)
{
    $files = glob($pattern, $flags);
    foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
        $files = array_merge($files, glob_recursive($dir . '/' . basename($pattern), $flags));
    }

    return $files;
}

function purgeCache($type = 'all')
{
    $theme = getAccount()->getThemeAttribute();

    $css = THEME_DIR . $theme . '/css/cache/*';
    $js = THEME_DIR . $theme . '/js/cache/*';
    if ($type == 'all') {
        array_map('unlink', array_filter((array) array_merge(glob($css))));
        array_map('unlink', array_filter((array) array_merge(glob($js))));
    } elseif (in_array($type, ['css', 'js'])) {
        array_map('unlink', array_filter((array) array_merge(glob(${$type}))));
    }
}
