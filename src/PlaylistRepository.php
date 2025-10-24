<?php

namespace App;

use RuntimeException;

class PlaylistRepository
{
    private string $directory;

    public function __construct(string $directory)
    {
        $normalized = rtrim($directory, DIRECTORY_SEPARATOR);
        if (!is_dir($normalized)) {
            if (!mkdir($normalized, 0775, true) && !is_dir($normalized)) {
                throw new RuntimeException('Unable to create playlist directory: ' . $normalized);
            }
        }
        $this->directory = $normalized;
    }

    /**
     * @return Album[]
     */
    public function getAlbums(): array
    {
        $pattern = $this->directory . DIRECTORY_SEPARATOR . '*.tsv';
        $files = glob($pattern) ?: [];
        natcasesort($files);

        $albums = [];
        foreach ($files as $filePath) {
            $albums[] = $this->parseAlbum($filePath);
        }

        return $albums;
    }

    public function albumExists(string $albumName): bool
    {
        $cleanName = $this->normalizeName($albumName);
        foreach ($this->getAlbums() as $album) {
            if ($this->normalizeName($album->name) === $cleanName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array{title:string,artist:string,duration:string}> $tracks
     */
    public function addAlbum(string $albumName, array $tracks): void
    {
        $albumName = trim($albumName);
        if ($albumName === '') {
            throw new RuntimeException('Album name is required.');
        }
        if ($this->albumExists($albumName)) {
            throw new RuntimeException('An album with the same name already exists.');
        }
        if (empty($tracks)) {
            throw new RuntimeException('At least one track is required.');
        }

        $fileName = $this->sanitizeAlbumName($albumName) . '.tsv';
        $filePath = $this->directory . DIRECTORY_SEPARATOR . $fileName;

        $lines = [];
        foreach ($tracks as $track) {
            $title = trim($track['title'] ?? '');
            $artist = trim($track['artist'] ?? '');
            $duration = trim($track['duration'] ?? '');

            if ($title === '' || $artist === '' || $duration === '') {
                throw new RuntimeException('Each track must include title, artist, and duration.');
            }

            $lines[] = implode("\t", [$title, $artist, $duration]);
        }

        $content = implode(PHP_EOL, $lines) . PHP_EOL;
        if (file_put_contents($filePath, $content) === false) {
            throw new RuntimeException('Failed to write album file.');
        }
    }

    private function parseAlbum(string $filePath): Album
    {
        $albumName = $this->albumNameFromPath($filePath);
        $rawLines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($rawLines === false) {
            throw new RuntimeException('Failed to read album file: ' . $filePath);
        }

        $tracks = [];
        $trackNumber = 1;
        foreach ($rawLines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = explode("\t", $line);
            $title = trim($parts[0] ?? '');
            $artist = trim($parts[1] ?? '');
            $duration = trim($parts[2] ?? '');

            $tracks[] = new Track($trackNumber, $title, $artist, $duration);
            $trackNumber++;
        }

        return new Album($albumName, $filePath, $tracks);
    }

    private function albumNameFromPath(string $filePath): string
    {
        $fileName = basename($filePath);
        return preg_replace('/\.tsv$/i', '', $fileName) ?? $fileName;
    }

    private function sanitizeAlbumName(string $albumName): string
    {
        $clean = preg_replace('/[\\x00-\\x1f\\/\\\\?%*:|"<>]/u', '-', $albumName);
        $clean = trim($clean ?? '', '- ');
        if ($clean === '') {
            $clean = 'album-' . date('Ymd-His');
        }

        return $clean;
    }

    private function normalizeName(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value);
        }

        return strtolower($value);
    }
}
