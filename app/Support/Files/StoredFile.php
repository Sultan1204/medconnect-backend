<?php

namespace App\Support\Files;

final readonly class StoredFile
{
    public function __construct(
        public string $path,
        public string $originalName,
        public string $mimeType,
        public int $size,
        public string $sha256,
    ) {}
}
