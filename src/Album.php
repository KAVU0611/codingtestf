<?php

declare(strict_types=1);

namespace App;

final class Album
{
    /**
     * @param Track[] $tracks
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly array $tracks
    ) {
    }
}
