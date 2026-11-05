<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    /**
     * Reuse the caller's request ID (or generate one), attach it to every log line and echo it back.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->incomingRequestId($request) ?? (string) Str::uuid();
        $startedAt = hrtime(true);

        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        Log::info('request.completed', [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 1),
        ]);

        return $response;
    }

    /**
     * Accept a caller-supplied ID only if it is short and made of safe characters, so it cannot pollute logs.
     */
    private function incomingRequestId(Request $request): ?string
    {
        $requestId = $request->headers->get(self::HEADER);

        if ($requestId === null || preg_match('/^[A-Za-z0-9._-]{1,128}$/', $requestId) !== 1) {
            return null;
        }

        return $requestId;
    }
}
