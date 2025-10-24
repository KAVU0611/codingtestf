<?php

namespace App;

class Album
{
    public string $name;
    public string $filePath;
    /** @var Track[] */
    public array $tracks;

    /**
     * @param Track[] $tracks
     */
    public function __construct(string $name, string $filePath, array $tracks)
    {
        $this->name = $name;
        $this->filePath = $filePath;
        $this->tracks = $tracks;
    }
}

