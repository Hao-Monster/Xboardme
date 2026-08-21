<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitServerRequestBody
{
    public function handle(Request $request, Closure $next, string $profile = 'control'): Response
    {
        $limit = (int) config("server_security.body_limits.{$profile}", 0);

        if ($limit <= 0) {
            abort(500, 'Invalid server request body limit configuration.');
        }

        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && ctype_digit($contentLength) && (int) $contentLength > $limit) {
            return $this->tooLarge();
        }

        if (strlen($request->getContent()) > $limit) {
            return $this->tooLarge();
        }

        return $next($request);
    }

    private function tooLarge(): JsonResponse
    {
        return response()->json(['message' => 'Request body too large.'], 413);
    }
}
