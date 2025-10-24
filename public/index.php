<?php

declare(strict_types=1);

use App\PlaylistRepository;

require __DIR__ . '/../src/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$defaultPlaylistRoot = dirname(__DIR__) . '/playlist';
$playlistRoot = $defaultPlaylistRoot;
$folderErrors = [];
$folderSuccess = [];
$albumErrors = [];
$albumSuccess = [];

$playlistRootEnvRaw = getenv('PLAYLIST_ROOT');
if (is_string($playlistRootEnvRaw) && trim($playlistRootEnvRaw) !== '') {
    $envNormalized = normalizeDirectoryPath($playlistRootEnvRaw);
    if ($envNormalized !== '' && is_dir($envNormalized)) {
        $playlistRoot = $envNormalized;
    }
}

if (isset($_SESSION['playlist_root']) && is_string($_SESSION['playlist_root'])) {
    $sessionNormalized = normalizeDirectoryPath((string)$_SESSION['playlist_root']);
    if ($sessionNormalized !== '' && is_dir($sessionNormalized)) {
        $playlistRoot = $sessionNormalized;
    }
}

$folderInputValue = $_SESSION['playlist_root_raw'] ?? '';
if ($folderInputValue === '') {
    if (is_string($playlistRootEnvRaw) && trim($playlistRootEnvRaw) !== '') {
        $folderInputValue = trim($playlistRootEnvRaw);
    } else {
        $folderInputValue = $playlistRoot;
    }
}

$postAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';

if ($postAction === 'set_root') {
    $inputPath = trim((string)($_POST['playlist_root'] ?? ''));
    if ($inputPath === '') {
        $folderErrors[] = 'Playlist folder path is required.';
    } else {
        $normalizedPath = normalizeDirectoryPath($inputPath);
        if ($normalizedPath === '') {
            $folderErrors[] = 'Playlist folder path is invalid.';
        } elseif (!is_dir($normalizedPath)) {
            $folderErrors[] = 'The specified playlist folder does not exist.';
        } else {
            $_SESSION['playlist_root'] = $normalizedPath;
            $_SESSION['playlist_root_raw'] = $inputPath;
            $playlistRoot = $normalizedPath;
            $folderInputValue = $inputPath;
            $folderSuccess[] = sprintf('Playlist folder changed to "%s".', $normalizedPath);
        }

        if ($inputPath !== '') {
            $folderInputValue = $inputPath;
        }
    }
}

$playlists = discoverPlaylists($playlistRoot);
$selectedPlaylistId = (string)($_POST['playlist'] ?? $_GET['playlist'] ?? '');
if ($selectedPlaylistId === '' || !array_key_exists($selectedPlaylistId, $playlists)) {
    $selectedPlaylistId = array_key_first($playlists);
}
$selectedPlaylist = $playlists[$selectedPlaylistId];
$selectedPlaylistRelativePath = relativePlaylistPath($selectedPlaylist['path'], $playlistRoot);

$repository = new PlaylistRepository($selectedPlaylist['path']);

if ($postAction === 'add_album') {
    $albumNameInput = $_POST['album_name'] ?? '';
    $rawTracksInput = $_POST['tracks'] ?? '';

    try {
        $tracks = parseTrackInput($rawTracksInput);
        $repository->addAlbum($albumNameInput, $tracks);
        $query = $_GET;
        $query['playlist'] = $selectedPlaylistId;
        $query['created'] = $albumNameInput;
        header('Location: ?' . http_build_query($query));
        exit;
    } catch (Throwable $exception) {
        $albumErrors[] = $exception->getMessage();
    }
}

if (isset($_GET['created'])) {
    $albumSuccess[] = sprintf(
        'Album "%s" was added to "%s".',
        (string)$_GET['created'],
        $selectedPlaylist['label']
    );
}

$filters = [
    'album' => trim((string)($_GET['filter_album'] ?? '')),
    'artist' => trim((string)($_GET['filter_artist'] ?? '')),
    'title_prefix' => trim((string)($_GET['filter_title_prefix'] ?? '')),
    'sort' => $_GET['sort'] ?? 'default',
];

$albums = $repository->getAlbums();
$songs = [];
$albumNames = [];
$artistNames = [];

foreach ($albums as $album) {
    $albumNames[$album->name] = true;
    foreach ($album->tracks as $track) {
        $songs[] = [
            'album' => $album->name,
            'track_number' => $track->number,
            'title' => $track->title,
            'artist' => $track->artist,
            'duration' => $track->duration,
            'duration_seconds' => durationToSeconds($track->duration),
        ];
        if ($track->artist !== '') {
            $artistNames[$track->artist] = true;
        }
    }
}

if ($filters['album'] !== '') {
    $songs = array_filter($songs, static function (array $song) use ($filters): bool {
        return stripos($song['album'], $filters['album']) !== false;
    });
}

if ($filters['artist'] !== '') {
    $songs = array_filter($songs, static function (array $song) use ($filters): bool {
        return stripos($song['artist'], $filters['artist']) !== false;
    });
}

if ($filters['title_prefix'] !== '') {
    $songs = array_filter($songs, static function (array $song) use ($filters): bool {
        return stripos($song['title'], $filters['title_prefix']) === 0;
    });
}

$songs = array_values($songs);

usort($songs, static function (array $a, array $b) use ($filters): int {
    if ($filters['sort'] === 'duration') {
        return $a['duration_seconds'] <=> $b['duration_seconds'];
    }

    $albumComparison = strcasecmp($a['album'], $b['album']);
    if ($albumComparison !== 0) {
        return $albumComparison;
    }

    return $a['track_number'] <=> $b['track_number'];
});

$totalDurationSeconds = array_reduce($songs, static function (int $carry, array $song): int {
    return $carry + ($song['duration_seconds'] ?? 0);
}, 0);

$albumNames = array_keys($albumNames);
sort($albumNames, SORT_FLAG_CASE | SORT_STRING);
$artistNames = array_keys($artistNames);
sort($artistNames, SORT_FLAG_CASE | SORT_STRING);

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
        $remainder = str_replace('\\', '/', substr($path, 2));
        $path = '/mnt/' . $drive . '/' . ltrim($remainder, '/');
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

/**
 * @return array<string,array{id:string,label:string,path:string}>
 */
function discoverPlaylists(string $rootDirectory): array
{
    if (!is_dir($rootDirectory)) {
        @mkdir($rootDirectory, 0775, true);
    }

    $playlists = [];
    $entries = is_dir($rootDirectory) ? scandir($rootDirectory) : [];
    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $rootDirectory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($fullPath)) {
                $playlists[$entry] = [
                    'id' => $entry,
                    'label' => $entry,
                    'path' => $fullPath,
                ];
            }
        }
    }

    $rootHasAlbums = (glob($rootDirectory . DIRECTORY_SEPARATOR . '*.tsv') ?: []) !== [];
    if ($rootHasAlbums) {
        $label = basename($rootDirectory) ?: 'Root playlist';
        $playlists['__root__'] = [
            'id' => '__root__',
            'label' => $label,
            'path' => $rootDirectory,
        ];
    }

    if ($playlists === []) {
        $label = basename($rootDirectory) ?: 'Root playlist';
        $playlists['__root__'] = [
            'id' => '__root__',
            'label' => $label,
            'path' => $rootDirectory,
        ];
    } else {
        uasort($playlists, static function (array $left, array $right): int {
            return strcasecmp($left['label'], $right['label']);
        });
    }

    return $playlists;
}

function relativePlaylistPath(string $path, string $rootDirectory): string
{
    $normalizedRoot = rtrim($rootDirectory, "/\\");
    $normalizedPath = rtrim($path, "/\\");

    if ($normalizedRoot !== '' && strncmp($normalizedPath, $normalizedRoot, strlen($normalizedRoot)) === 0) {
        $relative = ltrim(substr($normalizedPath, strlen($normalizedRoot)), "/\\");
        if ($relative === '') {
            $fallback = basename($rootDirectory);
            return $fallback !== '' ? $fallback : '.';
        }
        return $relative;
    }

    return $normalizedPath;
}

function parseTrackInput(string $input): array
{
    $lines = preg_split('/\r\n|\n|\r/', $input) ?: [];
    $tracks = [];

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        $parts = explode("\t", $line, 3);
        if (count($parts) < 3) {
            throw new RuntimeException('Each line must contain title, artist, and duration separated by tabs.');
        }

        [$title, $artist, $duration] = $parts;
        $tracks[] = [
            'title' => trim($title),
            'artist' => trim($artist),
            'duration' => trim($duration),
        ];
    }

    if (empty($tracks)) {
        throw new RuntimeException('Provide at least one track (title, artist, duration).');
    }

    return $tracks;
}

function durationToSeconds(string $duration): int
{
    $duration = trim($duration);
    if ($duration === '') {
        return 0;
    }

    $parts = explode(':', $duration);
    if (count($parts) === 2) {
        [$minutes, $seconds] = $parts;
        return ((int)$minutes * 60) + (int)$seconds;
    }

    if (count($parts) === 3) {
        [$hours, $minutes, $seconds] = $parts;
        return ((int)$hours * 3600) + ((int)$minutes * 60) + (int)$seconds;
    }

    return (int)$duration;
}

function formatSeconds(int $seconds): string
{
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    }

    return sprintf('%d:%02d', $minutes, $secs);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Playlist Viewer</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            margin: 0;
            background: #f7f7f9;
        }
        header {
            background: #1f2933;
            color: #fff;
            padding: 1.5rem;
        }
        main {
            padding: 1.5rem;
            max-width: 1100px;
            margin: 0 auto;
        }
        h1 {
            margin: 0;
            font-size: 1.8rem;
        }
        section {
            background: #fff;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 30px -15px rgba(31, 41, 51, 0.35);
        }
        form {
            display: grid;
            gap: 1rem;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.3rem;
        }
        input[type="text"],
        select,
        textarea {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 1rem;
        }
        textarea {
            min-height: 140px;
            line-height: 1.4;
        }
        button {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 0.75rem 1.4rem;
            font-size: 1rem;
            cursor: pointer;
            justify-self: start;
        }
        button:hover {
            background: #1d4ed8;
        }
        .messages {
            margin-bottom: 1rem;
        }
        .playlist-context {
            margin: 0.3rem 0 1rem;
            color: #475569;
            font-size: 0.95rem;
        }
        .message {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 0.5rem;
        }
        .message.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .message.success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        th, td {
            text-align: left;
            padding: 0.65rem 0.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f1f5f9;
            font-size: 0.95rem;
        }
        tr:hover td {
            background: #f9fafb;
        }
        .table-meta {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-top: 0.8rem;
            color: #475569;
            font-size: 0.95rem;
        }
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        @media (max-width: 700px) {
            section {
                padding: 1rem;
            }
            button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<header>
    <h1>Playlist Viewer</h1>
    <p>Select a playlist folder and browse or add albums stored as TSV files.</p>
</header>
<main>
    <section>
        <h2>Playlist Folder</h2>
        <p class="playlist-context">
            Enter the directory that contains your playlist folders or TSV albums.
            Windows paths are converted automatically.
        </p>
        <div class="messages">
            <?php foreach ($folderErrors as $error): ?>
                <div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
            <?php foreach ($folderSuccess as $message): ?>
                <div class="message success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="set_root">
            <div>
                <label for="playlist_root">Playlist folder path</label>
                <input type="text" id="playlist_root" name="playlist_root" value="<?= htmlspecialchars($folderInputValue, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <button type="submit">Use this folder</button>
        </form>
        <p class="playlist-context">
            Currently reading from: <code><?= htmlspecialchars($playlistRoot, ENT_QUOTES, 'UTF-8') ?></code>
        </p>
    </section>

    <section>
        <h2>Add Album</h2>
        <p class="playlist-context">
            Saving to folder: <code><?= htmlspecialchars($selectedPlaylistRelativePath, ENT_QUOTES, 'UTF-8') ?></code>.
            Use the selector below to switch playlists.
        </p>
        <div class="messages">
            <?php foreach ($albumErrors as $error): ?>
                <div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
            <?php foreach ($albumSuccess as $message): ?>
                <div class="message success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="add_album">
            <input type="hidden" name="playlist" value="<?= htmlspecialchars($selectedPlaylistId, ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <label for="album_name">Album name</label>
                <input type="text" id="album_name" name="album_name" placeholder="e.g. Acoustic Sessions">
            </div>
            <div>
                <label for="tracks">Tracks (one per line: Title[TAB]Artist[TAB]Duration)</label>
                <textarea id="tracks" name="tracks" placeholder="Sunrise&#9;Nova&#9;3:42&#10;Midnight Drive&#9;Atlas&#9;4:18"></textarea>
            </div>
            <button type="submit">Save album</button>
        </form>
    </section>

    <section>
        <h2>Playlist</h2>
        <p class="playlist-context">Viewing folder: <code><?= htmlspecialchars($selectedPlaylistRelativePath, ENT_QUOTES, 'UTF-8') ?></code></p>
        <form method="get" class="filters">
            <div>
                <label for="filter_playlist">Playlist folder</label>
                <select id="filter_playlist" name="playlist">
                    <?php foreach ($playlists as $playlistOption): ?>
                        <option value="<?= htmlspecialchars($playlistOption['id'], ENT_QUOTES, 'UTF-8') ?>" <?= $playlistOption['id'] === $selectedPlaylistId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($playlistOption['label'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="filter_album">Album</label>
                <input list="album-options" type="text" id="filter_album" name="filter_album" value="<?= htmlspecialchars($filters['album'], ENT_QUOTES, 'UTF-8') ?>">
                <datalist id="album-options">
                    <?php foreach ($albumNames as $name): ?>
                        <option value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label for="filter_artist">Artist</label>
                <input list="artist-options" type="text" id="filter_artist" name="filter_artist" value="<?= htmlspecialchars($filters['artist'], ENT_QUOTES, 'UTF-8') ?>">
                <datalist id="artist-options">
                    <?php foreach ($artistNames as $name): ?>
                        <option value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label for="filter_title_prefix">Title prefix</label>
                <input type="text" id="filter_title_prefix" name="filter_title_prefix" placeholder="e.g. A" value="<?= htmlspecialchars($filters['title_prefix'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <label for="sort">Sort</label>
                <select id="sort" name="sort">
                    <option value="default" <?= $filters['sort'] === 'default' ? 'selected' : '' ?>>Album &amp; track order</option>
                    <option value="duration" <?= $filters['sort'] === 'duration' ? 'selected' : '' ?>>Duration (short to long)</option>
                </select>
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit">Apply</button>
            </div>
        </form>

        <div class="table-meta">
            <span><?= count($songs) ?> track(s)</span>
            <span>Total duration: <?= formatSeconds($totalDurationSeconds) ?></span>
        </div>

        <table>
            <thead>
            <tr>
                <th>Album</th>
                <th>#</th>
                <th>Title</th>
                <th>Artist</th>
                <th>Duration</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($songs)): ?>
                <tr>
                    <td colspan="5">No tracks match your filters.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($songs as $song): ?>
                    <tr>
                        <td><?= htmlspecialchars($song['album'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)$song['track_number'] ?></td>
                        <td><?= htmlspecialchars($song['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($song['artist'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($song['duration'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
