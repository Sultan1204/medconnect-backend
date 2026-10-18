<?php

namespace App\Support\Files\Contracts;

/**
 * Malware scanning for user uploads. Production must use a real engine
 * (e.g. ClamAV daemon or a cloud scanning service) before files are stored.
 */
interface FileScanner
{
    /**
     * @return bool true when the file is clean
     */
    public function isClean(string $absolutePath): bool;
}
