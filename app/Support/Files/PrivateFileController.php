<?php

namespace App\Support\Files;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a private file for a valid, unexpired signed link. The link itself
 * is the capability: it is only generated after a policy check and expires
 * within minutes.
 *
 * @tags Files
 */
class PrivateFileController
{
    /**
     * Download a private file via a signed link.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, string $token, PrivateFileStore $files): StreamedResponse
    {
        $file = $files->decodeToken($token);
        abort_if($file === null || ! $files->disk()->exists($file['path']), 404);

        $response = $files->disk()->download($file['path'], $file['name']);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
