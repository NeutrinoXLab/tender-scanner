<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSeapWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('services.seap_worker.server'), 404);
        abort_unless($request->isSecure(), 403, 'HTTPS obligatoriu.');
        $token = (string) config('services.seap_worker.token');
        abort_unless(strlen($token) >= 32 && hash_equals($token, (string) $request->bearerToken()), 401, 'Worker neautorizat.');
        $maximum = max(1024, (int) config('services.seap_worker.max_bytes'));
        abort_if((int) $request->header('Content-Length', 0) > $maximum || strlen($request->getContent()) > $maximum, 413, 'Lot prea mare.');

        return $next($request);
    }
}
