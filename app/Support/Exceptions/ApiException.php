<?php

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * Domain error with a stable, machine-readable code.
 *
 * Throw from actions/services; it is rendered as
 * {"error": {"code": ..., "message": ..., "details": ...}}.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(string $code, string $message, int $status = 422, array $details = []): self
    {
        return new self($code, $message, $status, $details);
    }

    public static function forbidden(string $message = 'You are not allowed to perform this action.', string $code = 'forbidden'): self
    {
        return new self($code, $message, 403);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($code, $message, 409);
    }
}
