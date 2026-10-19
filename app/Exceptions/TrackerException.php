<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class TrackerException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    /** The user's YouTrack token is missing/expired/rejected: they have to log in again. */
    public function needsLogin(): bool
    {
        return $this->status === 401;
    }

    /** JSON error for the API. A 401 carries `reauth` so the browser signs the user out and sends them to the login. */
    public function response(array $extra = [], int $default = 502): JsonResponse
    {
        $status = match (true) {
            $this->needsLogin() => 401,
            $this->status === 404 => 404,
            default => $default,
        };

        return response()->json(['message' => $this->getMessage(), 'reauth' => $this->needsLogin()] + $extra, $status);
    }
}
