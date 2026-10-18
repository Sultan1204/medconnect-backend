<?php

namespace App\Support\Files;

use App\Support\Files\Contracts\FileScanner;
use RuntimeException;

/**
 * Development/test scanner: flags only the industry-standard EICAR test
 * signature. It provides NO real protection and refuses to run in production.
 */
class MockFileScanner implements FileScanner
{
    public function isClean(string $absolutePath): bool
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The mock file scanner must not be used in production.');
        }

        return ! str_contains((string) file_get_contents($absolutePath), self::eicarSignature());
    }

    /**
     * Assembled at runtime so this source file is not itself flagged by
     * antivirus software on developer machines.
     */
    public static function eicarSignature(): string
    {
        return implode('', ['X5O!P%@AP[4\\PZX54(P^)7CC)7}$', 'EICAR-STANDARD-', 'ANTIVIRUS-TEST-FILE!$H+H*']);
    }
}
