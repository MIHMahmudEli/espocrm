<?php

declare(strict_types=1);

/**
 * EspoCRM API diagnostics - TEMPORARY.
 *
 * Place this file in: public/api/v1/probe.php
 * Open: https://your-domain/api/v1/probe.php
 * Copy the whole output, then DELETE this file.
 */

header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__, 3);

echo "=== EspoCRM API diagnostics ===\n";
echo "root = $root\n";
echo "PHP  = " . PHP_VERSION . "\n";

/* ---------------------------------------------------------- 1. route files */

$routes = [
    'application/Espo/Resources/routes.json',
    'application/Espo/Modules/Crm/Resources/routes.json',
    'custom/Espo/Custom/Resources/routes.json',
];

echo "\n-- 1. route definition files --\n";

$routesOk = true;

foreach ($routes as $rel) {
    $path = $root . '/' . $rel;

    if (!is_file($path)) {
        echo "  MISSING     $rel\n";
        $routesOk = false;

        continue;
    }

    $size = filesize($path);
    $data = json_decode((string) file_get_contents($path));

    if ($data === null || !is_array($data)) {
        echo "  BROKEN      $rel  ($size bytes, not valid JSON)\n";
        $routesOk = false;

        continue;
    }

    printf("  OK          %s  (%d bytes, %d routes)\n", $rel, $size, count($data));
}

echo "\n  public/api/v1/index.php : " . (is_file($root . '/public/api/v1/index.php') ? 'present' : 'MISSING');
echo "\n  public/api/v1/.htaccess : " . (is_file($root . '/public/api/v1/.htaccess') ? 'present' : 'MISSING');
echo "\n";

/* ---------------------------------------------------------------- 2. cache */

echo "\n-- 2. cache --\n";

function probe_delete_files(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $count = 0;

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path)) {
            $count += probe_delete_files($path);
        } else if (is_file($path) && @unlink($path)) {
            $count++;
        }
    }

    return $count;
}

$cacheDir = $root . '/data/cache/application';

if (!is_dir($cacheDir)) {
    echo "  data/cache/application/ does not exist\n";
} else if (!is_writable($cacheDir)) {
    echo "  NOT WRITABLE - could not clear data/cache/application/\n";
    echo "  set the folder to 755/775 and run this script again\n";
} else {
    printf("  cleared %d cached file(s)\n", probe_delete_files($cacheDir));
}

/* --------------------------------------------------------- 3. directories */

echo "\n-- 3. directories --\n";

foreach (['data', 'data/cache', 'data/cache/application', 'application', 'custom'] as $rel) {
    $path = $root . '/' . $rel;

    if (!is_dir($path)) {
        echo "  MISSING     $rel\n";

        continue;
    }

    printf("  %-27s exists  writable=%s\n", $rel, is_writable($path) ? 'yes' : 'NO');
}

/* --------------------------------------------- 4. what the API router sees */

echo "\n-- 4. what the API router sees --\n";

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');

echo "  SCRIPT_NAME = $scriptName\n";
echo "  REQUEST_URI = $requestUri\n";
echo "  PATH_INFO   = " . ($pathInfo === '' ? '(none)' : $pathInfo) . "\n";

// Same logic as \Espo\Core\Utils\Route::detectBasePath()
$scriptNameParsed = (string) parse_url($scriptName, PHP_URL_PATH);
$scriptNameModified = str_replace('public/api/', 'api/', $scriptNameParsed);
$scriptDir = dirname($scriptNameModified);
$uri = (string) parse_url('http://any.com' . $requestUri, PHP_URL_PATH);

if (stripos($uri, $scriptNameParsed) === 0) {
    $basePath = $scriptNameParsed;
} else if ($scriptDir !== '/' && stripos($uri, $scriptDir) === 0) {
    $basePath = $scriptDir;
} else {
    $basePath = '';
}

echo "  BASE_PATH   = " . ($basePath === '' ? '(empty)' : $basePath) . "\n";

$basePathOk = $basePath !== '' && str_ends_with($basePath, '/api/v1');

/* -------------------------------------------------------- 5. live re-check */

echo "\n-- 5. live re-check of the API --\n";

$suffix = '/api/v1/probe.php';
$siteBase = str_ends_with($uri, $suffix) ? substr($uri, 0, -strlen($suffix)) : '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$checkUrl = $host !== '' ? $scheme . '://' . $host . $siteBase . '/api/v1/' : '';

echo "  url = " . ($checkUrl === '' ? '(cannot build)' : $checkUrl) . "\n";

$liveStatus = '';

if ($checkUrl !== '') {
    $result = @file_get_contents($checkUrl, false, stream_context_create([
        'http' => ['timeout' => 20, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]));

    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $m)) {
            $liveStatus = $m[1];
        }
    }

    if ($liveStatus === '') {
        echo "  no response (allow_url_fopen may be disabled)\n";
        echo "  open the url above in your browser instead\n";
    } else {
        echo "  HTTP $liveStatus\n";
        echo "  expected: 401 (installed) or the installer page (not installed)\n";
    }
}

/* --------------------------------------------------------------- 6. verdict */

echo "\n-- VERDICT --\n";

if (!$routesOk) {
    echo "  Route files are MISSING or BROKEN.\n";
    echo "  Fix: re-upload the whole package as ONE zip, then extract it on the\n";
    echo "  server (File Manager > Upload > Extract). Do not drag files over FTP.\n";
} else if ($basePath === '') {
    echo "  BASE_PATH is EMPTY. The API cannot tell where it is mounted, so no\n";
    echo "  route ever matches and every request returns 404.\n";
    echo "  This is a server / rewrite path problem - send me this whole output.\n";
} else if (!$basePathOk) {
    echo "  BASE_PATH is '$basePath' but it must end with /api/v1.\n";
    echo "  Send me this whole output - this is a server / rewrite path problem.\n";
} else if ($liveStatus === '401' || $liveStatus === '200') {
    echo "  The API answers with HTTP $liveStatus - routing works now.\n";
    echo "  Continue with the installer.\n";
} else if ($liveStatus === '404') {
    echo "  Base path and route files look fine, but the API still returns 404.\n";
    echo "  Send me this whole output.\n";
} else {
    echo "  Base path and route files look fine.\n";
    echo "  Check " . ($checkUrl === '' ? 'https://your-domain/api/v1/' : $checkUrl) . " yourself:\n";
    echo "  it must return 401.\n";
}

echo "\n  DELETE public/api/v1/probe.php when you are done.\n";
