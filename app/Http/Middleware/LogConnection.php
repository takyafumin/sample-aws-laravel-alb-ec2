<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogConnection
{
    /**
     * 毎リクエストの接続元情報を記録する。WhoAmIController と同じ項目を
     * ログにも出すことで、SSM で入って tail -f しながら比較できるようにする。
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('connection', [
            'REMOTE_ADDR' => $request->server('REMOTE_ADDR'),
            'X-Forwarded-For' => $request->header('X-Forwarded-For'),
            'X-Forwarded-Proto' => $request->header('X-Forwarded-Proto'),
            'X-Forwarded-Host' => $request->header('X-Forwarded-Host'),
            'X-Forwarded-Port' => $request->header('X-Forwarded-Port'),
            'Host' => $request->header('Host'),
            'ip' => $request->ip(),
            'ips' => $request->ips(),
            'host' => $request->getHost(),
            'http_host' => $request->getHttpHost(),
            'is_secure' => $request->isSecure(),
            'scheme' => $request->getScheme(),
            'port' => $request->getPort(),
            'full_url' => $request->fullUrl(),
        ]);

        return $next($request);
    }
}
