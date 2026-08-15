<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class EnvSet extends Command
{
    /**
     * sed 等を使わずに .env の値を書き換えるための薄いラッパー。
     * TRUST_PROXIES / TRUST_HOSTS をローカルで気軽に切り替える用途を想定。
     * php artisan key:generate が .env を書き換える手法（正規表現置換）と同じ考え方。
     */
    protected $signature = 'env:set {key : 例: TRUST_PROXIES} {value? : 省略時は空文字にする}';

    protected $description = '.env の KEY=VALUE を書き換える（無ければ追記する）';

    public function handle(): int
    {
        $path = base_path('.env');

        if (! file_exists($path)) {
            $this->error('.env が見つかりません。cp .env.example .env で作成してください。');

            return self::FAILURE;
        }

        $key = $this->argument('key');
        $value = (string) $this->argument('value');
        $contents = file_get_contents($path);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $line = "{$key}={$value}";

        $contents = preg_match($pattern, $contents)
            ? preg_replace($pattern, $line, $contents)
            : rtrim($contents).PHP_EOL.$line.PHP_EOL;

        file_put_contents($path, $contents);

        $this->info(".env に反映: {$line}");

        return self::SUCCESS;
    }
}
