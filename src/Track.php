<?php

declare(strict_types=1);

namespace App;

final class Track
{
    public function __construct(
        public readonly int $number,
        public readonly string $title,
        public readonly string $artist,
        public readonly string $duration
    ) {
    }
}
