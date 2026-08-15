<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// withMiddleware() のコールバックは HttpKernel 解決時（LoadEnvironmentVariables より前）に
// 実行されるため、通常のリクエストライフサイクルではまだ .env が読み込まれていない。
// TRUST_PROXIES/TRUST_HOSTS を env() で確実に参照できるよう、ここで明示的に .env を読み込む。
if (is_file(dirname(__DIR__).'/.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // --- TrustProxies: env('TRUST_PROXIES') = none | all | <CIDR,CIDR...> ---
        $tp = env('TRUST_PROXIES', 'none');
        if ($tp === 'all') {
            $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_AWS_ELB);
        } elseif ($tp !== 'none' && $tp !== null && $tp !== '') {
            $middleware->trustProxies(at: explode(',', $tp), headers: Request::HEADER_X_FORWARDED_AWS_ELB);
        }
        // none の場合は trustProxies を呼ばない = プロキシを信頼しない（ベースライン）

        // --- TrustHosts: env('TRUST_HOSTS') = カンマ区切りの正規表現パターン（空なら全許可） ---
        $th = env('TRUST_HOSTS', '');
        if (is_string($th) && $th !== '') {
            $middleware->trustHosts(at: explode(',', $th));
        }

        // --- POST /whoami を CSRF 除外 ---
        $middleware->validateCsrfTokens(except: ['whoami']);

        // --- 全リクエストの接続元をログ ---
        $middleware->append(\App\Http\Middleware\LogConnection::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
