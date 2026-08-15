<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

class WhoAmICheck extends Command
{
    /**
     * curl の代わりに、実際のHTTPカーネル（ミドルウェアスタック一式）を経由して
     * /whoami 等を叩き、現在の .env（TRUST_PROXIES/TRUST_HOSTS）の効果を確認する。
     * php artisan serve を起動しなくてもよい。
     */
    protected $signature = 'whoami:check
        {--path=/whoami : リクエストするパス}
        {--method=GET : GET または POST}
        {--for= : X-Forwarded-For ヘッダの値}
        {--proto= : X-Forwarded-Proto ヘッダの値}
        {--port= : X-Forwarded-Port ヘッダの値}
        {--host= : Host ヘッダの値（未指定なら既定のまま）}';

    protected $description = '.env の TRUST_PROXIES / TRUST_HOSTS の効果を、実HTTPカーネル経由でcurlなしに確認する';

    public function handle(Kernel $kernel): int
    {
        $server = [];

        if (! is_null($for = $this->option('for'))) {
            $server['HTTP_X_FORWARDED_FOR'] = $for;
        }
        if (! is_null($proto = $this->option('proto'))) {
            $server['HTTP_X_FORWARDED_PROTO'] = $proto;
        }
        if (! is_null($port = $this->option('port'))) {
            $server['HTTP_X_FORWARDED_PORT'] = $port;
        }

        $request = Request::create($this->option('path'), $this->option('method'), [], [], [], $server);

        if (! is_null($host = $this->option('host'))) {
            $request->headers->set('Host', $host);
        }

        $response = $kernel->handle($request);

        $this->line((string) $response->getStatusCode());

        $decoded = json_decode($response->getContent(), true);
        $this->line(json_encode(
            $decoded ?? $response->getContent(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        $kernel->terminate($request, $response);

        return $response->isSuccessful() || $response->isRedirection()
            ? self::SUCCESS
            : self::FAILURE;
    }
}
