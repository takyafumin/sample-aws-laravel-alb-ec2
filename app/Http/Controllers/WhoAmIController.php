<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhoAmIController extends Controller
{
    /**
     * TrustProxies / TrustHosts の効果を可視化するため、生ヘッダと
     * Laravel が解決した値の両方を返す。GET/POST 共通ハンドラ。
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'raw' => [
                'REMOTE_ADDR' => $request->server('REMOTE_ADDR'),
                'X-Forwarded-For' => $request->header('X-Forwarded-For'),
                'X-Forwarded-Proto' => $request->header('X-Forwarded-Proto'),
                'X-Forwarded-Host' => $request->header('X-Forwarded-Host'),
                'X-Forwarded-Port' => $request->header('X-Forwarded-Port'),
                'Host' => $request->header('Host'),
            ],
            'resolved' => [
                'ip' => $request->ip(),
                'ips' => $request->ips(),
                'host' => $request->getHost(),
                'http_host' => $request->getHttpHost(),
                'is_secure' => $request->isSecure(),
                'scheme' => $request->getScheme(),
                'port' => $request->getPort(),
                'full_url' => $request->fullUrl(),
            ],
            'trust_config' => [
                'TRUST_PROXIES' => env('TRUST_PROXIES'),
                'TRUST_HOSTS' => env('TRUST_HOSTS'),
            ],
            'method' => $request->method(),
        ]);
    }
}
