<?php

namespace App\Support\Files;

use App\Support\Exceptions\ApiException;
use App\Support\Files\Contracts\FileScanner;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Stores sensitive uploads on the private disk and hands out short-lived
 * download links. Callers must authorise access *before* requesting a link.
 */
class PrivateFileStore
{
    public function __construct(private readonly FileScanner $scanner) {}

    public function store(UploadedFile $file, string $directory): StoredFile
    {
        $realPath = (string) $file->getRealPath();

        if (! $this->scanner->isClean($realPath)) {
            throw ApiException::make('file_rejected', 'The file was rejected by the security scan.');
        }

        // Server-side detection; the client-supplied MIME type is never trusted.
        $mime = (string) $file->getMimeType();
        $extension = $file->guessExtension() ?: 'bin';
        $path = trim($directory, '/').'/'.Str::uuid7().'.'.$extension;

        $this->disk()->putFileAs(dirname($path), $file, basename($path));

        return new StoredFile(
            path: $path,
            originalName: self::safeName($file->getClientOriginalName(), $extension),
            mimeType: $mime,
            size: (int) $file->getSize(),
            sha256: (string) hash_file('sha256', $realPath),
        );
    }

    /**
     * A download link valid for a few minutes. Uses the object store's native
     * pre-signed URLs where available, otherwise an encrypted, signed app route.
     */
    public function temporaryUrl(string $path, string $downloadName, int $minutes = 5): string
    {
        $expires = now()->addMinutes($minutes);
        $disk = $this->disk();

        if (config('filesystems.disks.'.$this->diskName().'.driver') === 's3') {
            return $disk->temporaryUrl($path, $expires, [
                'ResponseContentDisposition' => 'attachment; filename="'.addslashes($downloadName).'"',
            ]);
        }

        return URL::temporarySignedRoute('private-files.show', $expires, [
            'token' => Crypt::encryptString(json_encode(['p' => $path, 'n' => $downloadName, 'e' => $expires->timestamp], JSON_THROW_ON_ERROR)),
        ]);
    }

    /**
     * @return array{path: string, name: string}|null
     */
    public function decodeToken(string $token): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data) || Carbon::createFromTimestamp($data['e'] ?? 0)->isPast()) {
            return null;
        }

        return ['path' => (string) $data['p'], 'name' => (string) $data['n']];
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    private function diskName(): string
    {
        return (string) config('medconnect.files.disk');
    }

    private static function safeName(string $name, string $extension): string
    {
        $base = Str::of(pathinfo($name, PATHINFO_FILENAME))->ascii()->replaceMatches('/[^A-Za-z0-9 _.-]/', '')->limit(100, '')->trim();

        return ($base->isEmpty() ? 'document' : (string) $base).'.'.$extension;
    }
}
