<?php

declare(strict_types=1);

use App\PlaylistRepository;

require __DIR__ . '/../src/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
        return;
    }

    $rawPayload = file_get_contents('php://input');
    if ($rawPayload === false || trim($rawPayload) === '') {
        throw new RuntimeException('Request payload is empty.');
    }

    $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid payload structure.');
    }

    $playlistName = trim((string)($payload['playlist'] ?? ''));
    $files = $payload['files'] ?? null;

    if (!is_array($files) || $files === []) {
        throw new RuntimeException('No files were provided.');
    }

    $playlistRoot = resolvePlaylistRoot();
    if (!is_dir($playlistRoot) && !mkdir($playlistRoot, 0775, true) && !is_dir($playlistRoot)) {
        throw new RuntimeException('Unable to create playlist root directory.');
    }

    $playlistId = sanitizeFolderName($playlistName !== '' ? $playlistName : 'uploaded-playlist');
    if ($playlistId === '') {
        $playlistId = 'uploaded-playlist-' . date('Ymd-His');
    }

    $targetDirectory = $playlistRoot . DIRECTORY_SEPARATOR . $playlistId;
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
        throw new RuntimeException('Unable to create playlist directory.');
    }

    clearTargetDirectory($targetDirectory);

    $importedFiles = 0;
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }

        $fileNameRaw = trim((string)($file['name'] ?? ''));
        $contentRaw = (string)($file['content'] ?? '');

        if ($fileNameRaw === '' || stripos($fileNameRaw, '.tsv') === false) {
            continue;
        }

        $baseName = basename($fileNameRaw);
        $albumName = preg_replace('/\.tsv$/i', '', $baseName) ?? $baseName;
        $sanitizedFile = sanitizeFileName($albumName) . '.tsv';

        if ($sanitizedFile === '.tsv') {
            continue;
        }

        $content = normalizeLineEndings($contentRaw);
        $filePath = $targetDirectory . DIRECTORY_SEPARATOR . $sanitizedFile;

        if (file_put_contents($filePath, $content) === false) {
            throw new RuntimeException('Failed to write file: ' . $sanitizedFile);
        }

        $importedFiles++;
    }

    if ($importedFiles === 0) {
        throw new RuntimeException('No valid TSV files found in the uploaded folder.');
    }

    $_SESSION['playlist_root'] = $playlistRoot;
    $_SESSION['playlist_root_raw'] = $playlistRoot;

    $repository = new PlaylistRepository($targetDirectory);
    $albums = $repository->getAlbums();

    $trackCount = 0;
    foreach ($albums as $album) {
        $trackCount += count($album->tracks);
    }

    $response = [
        'status' => 'ok',
        'playlistId' => $playlistId,
        'playlistLabel' => $playlistName !== '' ? $playlistName : $playlistId,
        'albums' => count($albums),
        'tracks' => $trackCount,
        'relativePath' => relativePlaylistPath($targetDirectory, $playlistRoot),
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(400);
    $message = $exception->getMessage();
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
}

/**
 * @throws RuntimeException
 */
function resolvePlaylistRoot(): string
{
    $defaultRoot = dirname(__DIR__) . '/playlist';
    $playlistRoot = $defaultRoot;

    $envRootRaw = getenv('PLAYLIST_ROOT');
    if (is_string($envRootRaw) && trim($envRootRaw) !== '') {
        $envNormalized = normalizeDirectoryPath($envRootRaw);
        if ($envNormalized !== '' && is_dir($envNormalized)) {
            $playlistRoot = $envNormalized;
        }
    }

    if (isset($_SESSION['playlist_root']) && is_string($_SESSION['playlist_root'])) {
        $sessionNormalized = normalizeDirectoryPath($_SESSION['playlist_root']);
        if ($sessionNormalized !== '' && is_dir($sessionNormalized)) {
            $playlistRoot = $sessionNormalized;
        }
    }

    return rtrim($playlistRoot, "/\\");
}

function sanitizeFolderName(string $value): string
{
    $clean = preg_replace('/[\\x00-\\x1f\\/\\\\?%*:|"<>]/u', '-', $value);
    $clean = trim((string)$clean, '- ');

    if ($clean === '') {
        return '';
    }

    return preg_replace('/\s+/', '-', $clean);
}

function sanitizeFileName(string $value): string
{
    $clean = preg_replace('/[\\x00-\\x1f\\/\\\\?%*:|"<>]/u', '-', $value);
    $clean = trim((string)$clean, '- ');

    if ($clean === '') {
        return 'album-' . date('Ymd-His');
    }

    return preg_replace('/\s+/', '-', $clean);
}

function normalizeLineEndings(string $content): string
{
    $normalized = preg_replace('/\r\n|\r/', "\n", $content) ?? $content;
    if (!str_ends_with($normalized, "\n")) {
        $normalized .= "\n";
    }

    return $normalized;
}

function clearTargetDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = glob($directory . DIRECTORY_SEPARATOR . '*.tsv');
    if (!is_array($files)) {
        return;
    }

    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

function normalizeDirectoryPath(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if ($path[0] === '~') {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            $path = $home . substr($path, 1);
        }
    }

    if (preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
        $drive = strtolower($path[0]);
        $rest = str_replace('\\', '/', substr($path, 2));
        $path = '/mnt/' . $drive . '/' . ltrim($rest, '/');
    } else {
        $path = str_replace('\\', '/', $path);
    }

    $normalized = rtrim($path, "/\\");
    if ($normalized === '') {
        return '';
    }

    if (is_dir($normalized)) {
        $real = realpath($normalized);
        if ($real !== false) {
            $normalized = rtrim($real, "/\\");
        }
    }

    return $normalized;
}

function relativePlaylistPath(string $path, string $rootDirectory): string
{
    $normalizedRoot = rtrim($rootDirectory, "/\\");
    $normalizedPath = rtrim($path, "/\\");

    if ($normalizedRoot !== '' && str_starts_with($normalizedPath, $normalizedRoot)) {
        $suffix = ltrim(substr($normalizedPath, strlen($normalizedRoot)), "/\\");
        if ($suffix === '') {
            $fallback = basename($rootDirectory);
            return $fallback !== '' ? $fallback : '.';
        }

        return $suffix;
    }

    return $normalizedPath;
}
