<?php

namespace App;

class Track
{
    public int $number;
    public string $title;
    public string $artist;
    public string $duration;

    public function __construct(int $number, string $title, string $artist, string $duration)
    {
        $this->number = $number;
        $this->title = $title;
        $this->artist = $artist;
        $this->duration = $duration;
    }
}

