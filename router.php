<?php

/*
 * Nexora NuGet V3 reverse proxy for PHP 8.1+ shared hosting.
 * Upstream hosts are restricted to HTTPS *.nuget.org.
 * Package downloads are stored in isolated temporary files and deleted
 * immediately after the response has been sent (or when the request ends).
 */

$config = require __DIR__ . '/config.php';


/* =========================================================
 * Basic response
 * ========================================================= */

function respond(
    int $status,
    string $body,
    string $type = 'text/plain; charset=utf-8'
): never {

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: ' . $type);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    echo $body;
    exit;
}


/* =========================================================
 * Base64 URL helpers
 * ========================================================= */

function b64url_encode(string $value): string
{
    return rtrim(
        strtr(base64_encode($value), '+/', '-_'),
        '='
    );
}


function b64url_decode(string $value): string|false
{
    $value = strtr($value, '-_', '+/');

    return base64_decode(
        $value . str_repeat(
            '=',
            (4 - strlen($value) % 4) % 4
        ),
        true
    );
}


/* =========================================================
 * Gateway URL
 * ========================================================= */

function local_gateway_url(
    string $url,
    string $token
): string {

    $base = rtrim(
        dirname($_SERVER['SCRIPT_NAME'] ?? ''),
        '/\\'
    );

    $scheme = (
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    ) ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? '';

    return $scheme .
        '://' .
        $host .
        $base .
        '/' .
        rawurlencode($token) .
        '/gateway/' .
        b64url_encode($url);
}


/* =========================================================
 * Rewrite NuGet URLs inside JSON
 * ========================================================= */

function rewrite_urls(
    mixed $value,
    string $token
): mixed {

    if (is_array($value)) {

        foreach ($value as $k => $v) {
            $value[$k] = rewrite_urls($v, $token);
        }

        return $value;
    }

    if (
        is_string($value) &&
        preg_match(
            '~^https://[a-z0-9.-]*\.?nuget\.org(?::443)?/~i',
            $value
        )
    ) {
        return local_gateway_url($value, $token);
    }

    return $value;
}


/* =========================================================
 * Validate upstream URL
 * ========================================================= */

function valid_upstream(string $url): bool
{
    $p = parse_url($url);

    if (!$p || !isset($p['host'])) {
        return false;
    }

    if (strtolower($p['scheme'] ?? '') !== 'https') {
        return false;
    }

    if (
        isset($p['user']) ||
        isset($p['pass']) ||
        (
            isset($p['port']) &&
            (int)$p['port'] !== 443
        )
    ) {
        return false;
    }

    $host = strtolower($p['host']);

    return (
        $host === 'nuget.org' ||
        str_ends_with($host, '.nuget.org')
    );
}


/* =========================================================
 * cURL helpers
 * ========================================================= */

function curl_https_options(): array
{
    $options = [];

    if (defined('CURLOPT_PROTOCOLS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }

    if (defined('CURLOPT_REDIR_PROTOCOLS')) {
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    }

    return $options;
}


function curl_content_length($ch): int
{
    if (defined('CURLINFO_CONTENT_LENGTH_DOWNLOAD_T')) {
        $value = curl_getinfo(
            $ch,
            CURLINFO_CONTENT_LENGTH_DOWNLOAD_T
        );
    } else {
        $value = curl_getinfo(
            $ch,
            CURLINFO_CONTENT_LENGTH_DOWNLOAD
        );
    }

    return is_numeric($value) ? (int)$value : -1;
}


/* =========================================================
 * Generic upstream request (JSON / service resources)
 * ========================================================= */

function curl_fetch(
    string $url,
    int $maxBytes = 20971520
): array {

    $body = '';
    $responseStatus = 0;
    $tooLarge = false;

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'status' => 0,
            'type' => 'application/json',
            'body' => '',
            'error' => 'Unable to initialize cURL',
            'too_large' => false
        ];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => (int)($GLOBALS['config']['connect_timeout'] ?? 25),
        CURLOPT_TIMEOUT => (int)($GLOBALS['config']['request_timeout'] ?? 240),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Nexora-NuGet-Proxy/1.0',

        // Automatically decode compressed JSON responses.
        CURLOPT_ENCODING => '',

        CURLOPT_HTTPHEADER => [
            'Accept: application/json, application/octet-stream, */*'
        ],

        CURLOPT_HEADERFUNCTION => function (
            $ch,
            string $line
        ) use (&$responseStatus): int {

            if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', trim($line), $m)) {
                $responseStatus = (int)$m[1];
            }

            return strlen($line);
        },

        CURLOPT_WRITEFUNCTION => function (
            $ch,
            string $chunk
        ) use (&$body, &$tooLarge, &$responseStatus, $maxBytes): int {

            $length = strlen($chunk);

            // Do not append bodies belonging to redirects or error responses.
            if ($responseStatus < 200 || $responseStatus >= 300) {
                return $length;
            }

            if (strlen($body) + $length > $maxBytes) {
                $tooLarge = true;
                return 0;
            }

            $body .= $chunk;
            return $length;
        }
    ];

    curl_setopt_array(
        $ch,
        $options + curl_https_options()
    );

    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)(
        curl_getinfo($ch, CURLINFO_CONTENT_TYPE)
        ?: 'application/json'
    );

    curl_close($ch);

    return [
        'ok' => $ok !== false,
        'status' => $status,
        'type' => $type,
        'body' => $body,
        'error' => $error,
        'too_large' => $tooLarge
    ];
}


/* =========================================================
 * Read an exact number of bytes from a file handle
 * ========================================================= */

function read_exact($fp, int $length): string|false
{
    if ($length < 0) {
        return false;
    }

    if ($length === 0) {
        return '';
    }

    $data = '';

    while (strlen($data) < $length) {

        $chunk = fread(
            $fp,
            $length - strlen($data)
        );

        if ($chunk === false || $chunk === '') {
            return false;
        }

        $data .= $chunk;
    }

    return $data;
}


/* =========================================================
 * Validate NuGet package ZIP structure
 * No ZipArchive PHP extension is required.
 * ========================================================= */

function is_valid_nupkg(string $file): bool
{
    if (!is_file($file) || !is_readable($file)) {
        return false;
    }

    $size = filesize($file);

    if ($size === false || $size < 22) {
        return false;
    }

    $fp = @fopen($file, 'rb');

    if (!$fp) {
        return false;
    }

    try {
        // EOCD must be within the last 65,557 bytes of a standard ZIP file.
        $tailSize = min($size, 65557);

        if (fseek($fp, -$tailSize, SEEK_END) !== 0) {
            return false;
        }

        $tail = read_exact($fp, $tailSize);

        if ($tail === false) {
            return false;
        }

        // Search backwards. A ZIP comment could contain the EOCD signature too.
        $searchLength = strlen($tail);
        $eocd = false;
        $eocdPosition = false;

        while ($searchLength > 0) {

            $candidate = strrpos(
                substr($tail, 0, $searchLength),
                "PK\x05\x06"
            );

            if ($candidate === false) {
                break;
            }

            if ($candidate + 22 <= strlen($tail)) {

                $candidateData = unpack(
                    'vdiskNumber/vcentralDiskNumber/ventriesOnDisk/ventriesTotal/VcentralDirectorySize/VcentralDirectoryOffset/vcommentLength',
                    substr($tail, $candidate + 4, 18)
                );

                if (
                    $candidateData &&
                    $candidate + 22 + $candidateData['commentLength'] === strlen($tail)
                ) {
                    $eocd = $candidateData;
                    $eocdPosition = $candidate;
                    break;
                }
            }

            $searchLength = $candidate;
        }

        if ($eocd === false || $eocdPosition === false) {
            return false;
        }

        // Reject multi-disk archives and inconsistent entry counts.
        if (
            $eocd['diskNumber'] !== 0 ||
            $eocd['centralDiskNumber'] !== 0 ||
            $eocd['entriesOnDisk'] !== $eocd['entriesTotal'] ||
            $eocd['entriesTotal'] < 1
        ) {
            return false;
        }

        // This proxy intentionally accepts standard ZIP packages, not ZIP64.
        if (
            $eocd['entriesTotal'] === 0xFFFF ||
            $eocd['centralDirectorySize'] === 0xFFFFFFFF ||
            $eocd['centralDirectoryOffset'] === 0xFFFFFFFF
        ) {
            return false;
        }

        $absoluteEocdPosition = (
            $size - $tailSize + $eocdPosition
        );

        $centralStart = (int)$eocd['centralDirectoryOffset'];
        $centralSize = (int)$eocd['centralDirectorySize'];
        $centralEnd = $centralStart + $centralSize;

        if (
            $centralSize < 46 ||
            $centralStart < 0 ||
            $centralEnd > $absoluteEocdPosition ||
            $centralEnd > $size
        ) {
            return false;
        }

        if (fseek($fp, $centralStart, SEEK_SET) !== 0) {
            return false;
        }

        $hasNuspec = false;

        for ($i = 0; $i < $eocd['entriesTotal']; $i++) {

            $centralHeader = read_exact($fp, 46);

            if (
                $centralHeader === false ||
                substr($centralHeader, 0, 4) !== "PK\x01\x02"
            ) {
                return false;
            }

            $entry = unpack(
                'vversionMade/vversionNeeded/vflags/vmethod/vmodTime/vmodDate/Vcrc32/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttributes/VexternalAttributes/VlocalOffset',
                substr($centralHeader, 4, 42)
            );

            if (!$entry) {
                return false;
            }

            if (
                $entry['nameLength'] < 1 ||
                $entry['diskStart'] !== 0 ||
                $entry['compressedSize'] === 0xFFFFFFFF ||
                $entry['uncompressedSize'] === 0xFFFFFFFF ||
                $entry['localOffset'] === 0xFFFFFFFF
            ) {
                return false;
            }

            $bytesToRead = (
                $entry['nameLength'] +
                $entry['extraLength'] +
                $entry['commentLength']
            );

            $currentPosition = ftell($fp);

            if (
                $currentPosition === false ||
                $currentPosition + $bytesToRead > $centralEnd
            ) {
                return false;
            }

            $entryName = read_exact(
                $fp,
                $entry['nameLength']
            );

            if ($entryName === false) {
                return false;
            }

            // Extra fields and per-entry comments are skipped but must be complete.
            if (
                read_exact($fp, $entry['extraLength']) === false ||
                read_exact($fp, $entry['commentLength']) === false
            ) {
                return false;
            }

            if (str_ends_with(strtolower($entryName), '.nuspec')) {
                $hasNuspec = true;
            }

            // Validate that the central directory points to a real local ZIP header.
            $centralNextPosition = ftell($fp);

            if (
                $centralNextPosition === false ||
                $entry['localOffset'] + 30 > $centralStart
            ) {
                return false;
            }

            if (fseek($fp, $entry['localOffset'], SEEK_SET) !== 0) {
                return false;
            }

            $localHeader = read_exact($fp, 30);

            if (
                $localHeader === false ||
                substr($localHeader, 0, 4) !== "PK\x03\x04"
            ) {
                return false;
            }

            $localLengths = unpack(
                'vnameLength/vextraLength',
                substr($localHeader, 26, 4)
            );

            if (!$localLengths) {
                return false;
            }

            $localName = read_exact(
                $fp,
                $localLengths['nameLength']
            );

            if (
                $localName === false ||
                $localName !== $entryName
            ) {
                return false;
            }

            $dataStart = (
                $entry['localOffset'] +
                30 +
                $localLengths['nameLength'] +
                $localLengths['extraLength']
            );

            $dataEnd = $dataStart + $entry['compressedSize'];

            if (
                $dataStart > $centralStart ||
                $dataEnd > $centralStart
            ) {
                return false;
            }

            if (fseek($fp, $centralNextPosition, SEEK_SET) !== 0) {
                return false;
            }
        }

        $finalCentralPosition = ftell($fp);

        if (
            $finalCentralPosition === false ||
            $finalCentralPosition !== $centralEnd ||
            !$hasNuspec
        ) {
            return false;
        }

        return true;

    } finally {
        fclose($fp);
    }
}


/* =========================================================
 * Temporary package storage
 * ========================================================= */

function package_temp_directory(array $config): string|false
{
    $base = (string)(
        $config['temp_dir'] ?? sys_get_temp_dir()
    );

    if ($base === '' || !is_dir($base) || !is_writable($base)) {
        return false;
    }

    $realBase = realpath($base);

    if ($realBase === false) {
        return false;
    }

    // Isolate this installation from other PHP applications on the same host.
    $directoryName = 'nexora-nuget-' . substr(
        hash('sha256', __DIR__),
        0,
        16
    );

    $directory = rtrim(
        $realBase,
        DIRECTORY_SEPARATOR
    ) . DIRECTORY_SEPARATOR . $directoryName;

    if (
        !is_dir($directory) &&
        !@mkdir($directory, 0700, true) &&
        !is_dir($directory)
    ) {
        return false;
    }

    if (!is_dir($directory) || !is_writable($directory)) {
        return false;
    }

    @chmod($directory, 0700);

    return $directory;
}


function cleanup_stale_package_temps(
    string $directory,
    string $prefix,
    int $maxAgeSeconds
): void {

    $cutoff = time() - max(600, $maxAgeSeconds);

    $files = glob(
        $directory . DIRECTORY_SEPARATOR . $prefix . '*'
    ) ?: [];

    foreach ($files as $file) {

        if (!is_file($file) || is_link($file)) {
            continue;
        }

        $modified = @filemtime($file);

        if ($modified !== false && $modified < $cutoff) {
            @unlink($file);
        }
    }
}


/* =========================================================
 * Download and serve a NuGet package without permanent cache
 * ========================================================= */

function serve_package(
    string $url,
    array $config
): never {

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $connectTimeout = (int)($config['connect_timeout'] ?? 25);
    $requestTimeout = (int)($config['request_timeout'] ?? 240);

    /* -----------------------------------------------------
     * HEAD: ask NuGet for headers only; do not download body.
     * ----------------------------------------------------- */

    if ($method === 'HEAD') {

        $ch = curl_init($url);

        if ($ch === false) {
            respond(502, 'Unable to initialize package HEAD request');
        }

        $options = [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Nexora-NuGet-Proxy/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/octet-stream, */*',
                'Accept-Encoding: identity'
            ]
        ];

        curl_setopt_array(
            $ch,
            $options + curl_https_options()
        );

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $length = curl_content_length($ch);

        curl_close($ch);

        if (
            $ok === false ||
            $status !== 200 ||
            !valid_upstream($effectiveUrl)
        ) {
            respond(
                $status >= 400 && $status <= 599 ? $status : 502,
                'Package HEAD request failed. HTTP: ' . $status .
                ' | Error: ' . $error
            );
        }

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/octet-stream');

            if ($length >= 0) {
                header('Content-Length: ' . $length);
            }

            header('Cache-Control: no-store, no-cache, must-revalidate, no-transform');
            header('X-Content-Type-Options: nosniff');
        }

        exit;
    }


    /* -----------------------------------------------------
     * GET: create a unique temporary file for this request.
     * ----------------------------------------------------- */

    $tempDirectory = package_temp_directory($config);

    if ($tempDirectory === false) {
        respond(
            500,
            'Temporary directory is not available or writable. Check temp_dir in config.php.'
        );
    }

    $prefix = 'nupkg_' . substr(
        hash('sha256', __DIR__),
        0,
        12
    ) . '_';

    cleanup_stale_package_temps(
        $tempDirectory,
        $prefix,
        (int)($config['stale_temp_age_seconds'] ?? 3600)
    );

    $temporaryFile = @tempnam(
        $tempDirectory,
        $prefix
    );

    if ($temporaryFile === false) {
        respond(500, 'Unable to create temporary package file');
    }

    // This also removes the file if PHP exits unexpectedly during the request.
    register_shutdown_function(
        static function () use ($temporaryFile): void {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    );

    @chmod($temporaryFile, 0600);

    $fp = @fopen($temporaryFile, 'wb');

    if (!$fp) {
        @unlink($temporaryFile);
        respond(500, 'Unable to open temporary package file');
    }

    $maxPackageBytes = (int)(
        $config['max_package_bytes'] ?? (200 * 1024 * 1024)
    );

    if ($maxPackageBytes < 1) {
        fclose($fp);
        @unlink($temporaryFile);
        respond(500, 'max_package_bytes must be greater than zero');
    }

    $received = 0;
    $responseStatus = 0;
    $tooLarge = false;
    $writeFailed = false;

    $ch = curl_init($url);

    if ($ch === false) {
        fclose($fp);
        @unlink($temporaryFile);
        respond(502, 'Unable to initialize package download');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $requestTimeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Nexora-NuGet-Proxy/1.0',
        CURLOPT_BUFFERSIZE => 262144,

        // Do not request gzip/deflate for ZIP packages.
        // We intentionally do not set CURLOPT_ENCODING here.
        CURLOPT_HTTPHEADER => [
            'Accept: application/octet-stream, */*',
            'Accept-Encoding: identity'
        ],

        CURLOPT_HEADERFUNCTION => function (
            $ch,
            string $line
        ) use (&$responseStatus): int {

            if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', trim($line), $m)) {
                $responseStatus = (int)$m[1];
            }

            return strlen($line);
        },

        CURLOPT_WRITEFUNCTION => function (
            $ch,
            string $chunk
        ) use (
            $fp,
            &$received,
            &$responseStatus,
            &$tooLarge,
            &$writeFailed,
            $maxPackageBytes
        ): int {

            $length = strlen($chunk);

            // Ignore redirect/error bodies; only write the final HTTP 200 body.
            if ($responseStatus !== 200) {
                return $length;
            }

            if ($received + $length > $maxPackageBytes) {
                $tooLarge = true;
                return 0;
            }

            // fwrite() may write fewer bytes than requested; complete the chunk.
            $offset = 0;

            while ($offset < $length) {

                $written = fwrite(
                    $fp,
                    substr($chunk, $offset)
                );

                if ($written === false || $written === 0) {
                    $writeFailed = true;
                    return 0;
                }

                $offset += $written;
            }

            $received += $length;
            return $length;
        }
    ];

    curl_setopt_array(
        $ch,
        $options + curl_https_options()
    );

    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $expectedLength = curl_content_length($ch);

    curl_close($ch);

    $flushOk = fflush($fp);
    $closeOk = fclose($fp);
    $actualSize = @filesize($temporaryFile);

    /* -----------------------------------------------------
     * Validate transfer result before sending anything.
     * ----------------------------------------------------- */

    if ($tooLarge) {
        @unlink($temporaryFile);
        respond(
            413,
            'Package exceeds max_package_bytes (' . $maxPackageBytes . ' bytes).'
        );
    }

    if ($writeFailed) {
        @unlink($temporaryFile);
        respond(502, 'Unable to write the downloaded package to temporary storage');
    }

    if (
        $ok === false ||
        $status !== 200 ||
        !valid_upstream($effectiveUrl)
    ) {
        @unlink($temporaryFile);

        respond(
            $status >= 400 && $status <= 599 ? $status : 502,
            'Package download failed. HTTP: ' . $status .
            ' | Received: ' . $received .
            ' | cURL: ' . $error
        );
    }

    if (
        !$flushOk ||
        !$closeOk ||
        $received < 1 ||
        $actualSize === false ||
        $actualSize !== $received
    ) {
        @unlink($temporaryFile);

        respond(
            502,
            'Incomplete temporary package file. Received: ' . $received .
            ' bytes; stored: ' . (string)$actualSize . ' bytes.'
        );
    }

    // -1 means the upstream did not provide a Content-Length header.
    if (
        $expectedLength >= 0 &&
        $received !== $expectedLength
    ) {
        @unlink($temporaryFile);

        respond(
            502,
            'Incomplete package download. Expected: ' . $expectedLength .
            ' bytes; received: ' . $received . ' bytes.'
        );
    }

    if (!is_valid_nupkg($temporaryFile)) {
        @unlink($temporaryFile);

        respond(
            502,
            'The upstream response is not a complete and valid NuGet ZIP package.'
        );
    }


    /* -----------------------------------------------------
     * Send the verified temporary package to NuGet client.
     * ----------------------------------------------------- */

    $downloadFp = @fopen($temporaryFile, 'rb');

    if (!$downloadFp) {
        @unlink($temporaryFile);
        respond(500, 'Unable to read verified temporary package file');
    }

    $downloadSize = @filesize($temporaryFile);

    if ($downloadSize === false) {
        fclose($downloadFp);
        @unlink($temporaryFile);
        respond(500, 'Unable to determine temporary package size');
    }

    // Prevent PHP output buffers or zlib from altering the binary response.
    @ini_set('zlib.output_compression', '0');

    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) {
            break;
        }
    }

    $requestedFilename = basename(
        (string)(parse_url($url, PHP_URL_PATH) ?: 'package.nupkg')
    );

    $requestedFilename = preg_replace(
        '/[^A-Za-z0-9._-]/',
        '_',
        $requestedFilename
    ) ?: 'package.nupkg';

    http_response_code(200);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $downloadSize);
    header('Content-Disposition: attachment; filename="' . $requestedFilename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, no-transform');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Accept-Ranges: none');
    header('X-Content-Type-Options: nosniff');

    // Keep the PHP process alive long enough to run the cleanup on client abort.
    $previousIgnoreUserAbort = ignore_user_abort(true);

    while (!feof($downloadFp)) {

        $chunk = fread($downloadFp, 1024 * 1024);

        if ($chunk === false || $chunk === '') {
            break;
        }

        echo $chunk;
        flush();

        if (connection_aborted()) {
            break;
        }
    }

    fclose($downloadFp);
    @unlink($temporaryFile);
    ignore_user_abort($previousIgnoreUserAbort);

    exit;
}


/* =========================================================
 * Request routing
 * ========================================================= */

$token = (string)($_GET['_token'] ?? '');
$route = (string)($_GET['_route'] ?? '');


/* ---------------------------------------------------------
 * Access token validation
 * --------------------------------------------------------- */

if (
    !hash_equals(
        (string)($config['access_token'] ?? ''),
        $token
    )
) {
    respond(404, 'Not found');
}


/* ---------------------------------------------------------
 * Allowed HTTP methods
 * --------------------------------------------------------- */

if (
    !in_array(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        ['GET', 'HEAD'],
        true
    )
) {
    header('Allow: GET, HEAD');
    respond(405, 'Method not allowed');
}


/* =========================================================
 * NuGet Service Index
 * ========================================================= */

if ($route === 'v3/index.json') {

    $result = curl_fetch(
        'https://api.nuget.org/v3/index.json',
        (int)($config['max_json_bytes'] ?? (20 * 1024 * 1024))
    );

    if (
        !$result['ok'] ||
        $result['status'] !== 200
    ) {
        respond(
            502,
            $result['too_large']
                ? 'NuGet service index exceeded max_json_bytes'
                : 'Unable to fetch NuGet service index. ' . $result['error']
        );
    }

    $json = json_decode(
        $result['body'],
        true
    );

    if (!is_array($json)) {
        respond(502, 'Invalid NuGet service index JSON');
    }

    $json = rewrite_urls(
        $json,
        $token
    );

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo json_encode(
        $json,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* =========================================================
 * Health Check
 * ========================================================= */

if ($route === 'health') {

    $checks = [];

    foreach ([
        'nuget_service_index' =>
            'https://api.nuget.org/v3/index.json',

        'azure_search' =>
            'https://azuresearch-ussc.nuget.org/query?q=newtonsoft&take=1'

    ] as $name => $url) {

        $r = curl_fetch(
            $url,
            2097152
        );

        $checks[$name] = [
            'success' => $r['ok'] && $r['status'] === 200,
            'http' => $r['status'],
            'error' => $r['error']
        ];
    }

    // Test that isolated temporary storage can be created and written.
    $tempDirectory = package_temp_directory($config);
    $tempWritable = false;

    if ($tempDirectory !== false) {

        $probe = @tempnam(
            $tempDirectory,
            'probe_'
        );

        if ($probe !== false) {
            $tempWritable = @file_put_contents($probe, 'ok') === 2;
            @unlink($probe);
        }
    }

    $checks['temporary_storage'] = [
        'success' => $tempWritable,
        'writable' => $tempWritable,
        'error' => $tempWritable
            ? ''
            : 'Temporary directory is not writable; check temp_dir in config.php.'
    ];

    // HEAD check confirms package endpoint access without downloading the package.
    $packageUrl = 'https://api.nuget.org/v3-flatcontainer/newtonsoft.json/13.0.3/newtonsoft.json.13.0.3.nupkg';
    $ch = curl_init($packageUrl);

    if ($ch !== false) {

        $headOptions = [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => (int)($config['connect_timeout'] ?? 25),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Nexora-NuGet-Proxy/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/octet-stream, */*',
                'Accept-Encoding: identity'
            ]
        ];

        curl_setopt_array(
            $ch,
            $headOptions + curl_https_options()
        );

        curl_exec($ch);

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        curl_close($ch);

        $checks['package_head'] = [
            'success' => $status === 200 && valid_upstream($effectiveUrl),
            'http' => $status,
            'error' => $error
        ];

    } else {

        $checks['package_head'] = [
            'success' => false,
            'http' => 0,
            'error' => 'Unable to initialize cURL'
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode(
        [
            'proxy' => 'Nexora NuGet V3',
            'package_cache' => 'disabled',
            'package_delivery' => 'temporary-file-per-request',
            'php' => PHP_VERSION,
            'checks' => $checks
        ],
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* =========================================================
 * Gateway
 * ========================================================= */

if (str_starts_with($route, 'gateway/')) {

    // Decode only the first segment. NuGet appends resource paths after it.
    $gatewayPath = substr(
        $route,
        8
    );

    $segments = explode(
        '/',
        $gatewayPath,
        2
    );

    $encoded = $segments[0];
    $suffix = $segments[1] ?? '';

    $upstream = b64url_decode(
        $encoded
    );

    if (
        $upstream === false ||
        !valid_upstream($upstream)
    ) {
        respond(400, 'Invalid upstream URL');
    }

    if ($suffix !== '') {
        $upstream = rtrim($upstream, '/') . '/' . $suffix;
    }

    // Forward NuGet query parameters, excluding proxy routing parameters.
    $extra = $_GET;

    unset(
        $extra['_token'],
        $extra['_route']
    );

    if ($extra) {

        $query = http_build_query(
            $extra
        );

        $upstream .= (
            str_contains($upstream, '?')
                ? '&'
                : '?'
        ) . $query;
    }

    // Validate again after suffix/query assembly.
    if (!valid_upstream($upstream)) {
        respond(400, 'Invalid upstream URL');
    }

    $path = strtolower(
        (string)parse_url(
            $upstream,
            PHP_URL_PATH
        )
    );

    if (str_ends_with($path, '.nupkg')) {
        serve_package(
            $upstream,
            $config
        );
    }

    // Fetch JSON or other NuGet service resources.
    $r = curl_fetch(
        $upstream,
        (int)($config['max_json_bytes'] ?? (20 * 1024 * 1024))
    );

    if (
        !$r['ok'] ||
        $r['status'] < 200 ||
        $r['status'] >= 300
    ) {
        $status = (
            $r['status'] >= 400 &&
            $r['status'] <= 599
        ) ? $r['status'] : 502;

        respond(
            $status,
            $r['too_large']
                ? 'Upstream response exceeded max_json_bytes'
                : ($r['error'] ?: 'Upstream request failed')
        );
    }

    $decoded = json_decode(
        $r['body'],
        true
    );

    if (is_array($decoded)) {

        $decoded = rewrite_urls(
            $decoded,
            $token
        );

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        echo json_encode(
            $decoded,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );

    } else {

        header('Content-Type: ' . $r['type']);
        header('Cache-Control: no-store');
        echo $r['body'];
    }

    exit;
}


/* =========================================================
 * Unknown route
 * ========================================================= */

respond(404, 'Not found');
