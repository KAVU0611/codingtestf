<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class PlaylistRepository
{
    private string $directory;

    public function __construct(string $directory)
    {
        $normalized = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($normalized === '') {
            throw new RuntimeException('Playlist directory cannot be empty.');
        }
        if (!is_dir($normalized)) {
            if (!mkdir($normalized, 0775, true) && !is_dir($normalized)) {
                throw new RuntimeException('Failed to create playlist directory: ' . $normalized);
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
        foreach ($files as $path) {
            $albums[] = $this->parseAlbum($path);
        }

        return $albums;
    }

    public function albumExists(string $albumName): bool
    {
        $target = $this->normalizeName($albumName);
        foreach ($this->getAlbums() as $album) {
            if ($this->normalizeName($album->name) === $target) {
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
        if ($tracks === []) {
            throw new RuntimeException('At least one track is required.');
        }

        $fileName = $this->sanitizeFileName($albumName) . '.tsv';
        $filePath = $this->directory . DIRECTORY_SEPARATOR . $fileName;

        $lines = [];
        foreach ($tracks as $track) {
            $title = trim($track['title'] ?? '');
            $artist = trim($track['artist'] ?? '');
            $duration = trim($track['duration'] ?? '');

            if ($title === '' || $artist === '' || $duration === '') {
                throw new RuntimeException('Each track requires title, artist, and duration.');
            }

            $lines[] = implode("\t", [$title, $artist, $duration]);
        }

        $payload = implode(PHP_EOL, $lines) . PHP_EOL;
        if (file_put_contents($filePath, $payload) === false) {
            throw new RuntimeException('Failed to write album file.');
        }
    }

    private function parseAlbum(string $path): Album
    {
        $name = $this->albumNameFromPath($path);
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read album file: ' . $path);
        }

        $tracks = [];
        $number = 1;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = explode("\t", $line);
            $title = trim($parts[0] ?? '');
            $artist = trim($parts[1] ?? '');
            $duration = trim($parts[2] ?? '');

            $tracks[] = new Track($number, $title, $artist, $duration);
            $number++;
        }

        return new Album($name, $path, $tracks);
    }

    private function albumNameFromPath(string $path): string
    {
        $basename = basename($path);
        return preg_replace('/\.tsv$/i', '', $basename) ?? $basename;
    }

    private function sanitizeFileName(string $albumName): string
    {
        $clean = preg_replace('/[\\x00-\\x1f\\/\\\\?%*:|"<>]/u', '-', $albumName);
        $clean = trim((string)$clean, '- ');
        if ($clean === '') {
            $clean = 'album-' . date('Ymd-His');
        }

        return $clean;
    }

    private function normalizeName(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
