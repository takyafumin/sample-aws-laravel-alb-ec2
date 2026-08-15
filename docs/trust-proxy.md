# TrustProxies / TrustHosts 解説

## TrustProxies が変えるもの

Laravelはデフォルトでは直接接続してきたピア（このリポジトリでは常にALBの内部IP）だけを信頼する。ALBやCDNなどのリバースプロキシ経由でアクセスされる環境では、クライアントが送ってきた（あるいはプロキシが付与した）`X-Forwarded-For` / `X-Forwarded-Proto` / `X-Forwarded-Host` / `X-Forwarded-Port` ヘッダを信頼するかどうかを `bootstrap/app.php` の `trustProxies()` で明示的に設定する必要がある。

- 信頼しない（デフォルト）: `$request->ip()` はALBの内部IP、`isSecure()` は実際の接続プロトコル（EC2からはHTTPなので false）を返す。ALBが付けた `X-Forwarded-Proto: https` は**無視**される。
- 信頼する（`trustProxies(at: '*', ...)` など）: `X-Forwarded-For` の先頭（＝実クライアントIP）が `ip()` に、`X-Forwarded-Proto` が `isSecure()`/`getScheme()` に反映される。

このリポジトリでは `env('TRUST_PROXIES')` の値で切り替える（[bootstrap/app.php](../bootstrap/app.php)）:

| TRUST_PROXIES | 意味 |
|---|---|
| `none`（既定） | `trustProxies()` を呼ばない＝どのプロキシも信頼しない |
| `all` | `at: '*'` で全プロキシを信頼 |
| `10.0.0.0/16` のようなCIDR（カンマ区切り可） | 指定したCIDRのみ信頼 |

ヘッダの解釈には ALB 向けの `Request::HEADER_X_FORWARDED_AWS_ELB` ビットマスクを使用している。

## TrustHosts が変えるもの

`trustHosts()` を設定すると、`Host` ヘッダ（および `X-Forwarded-Host`）が許可パターンにマッチしない場合、Laravelはリクエストを **400 Bad Request** で拒否する。ホストヘッダ偽装（キャッシュポイズニング等）対策。

このリポジトリでは `env('TRUST_HOSTS')`（カンマ区切りの正規表現）で切り替える。空文字なら `trustHosts()` を呼ばず、全Hostを許可する。

## 真理値表

| TRUST_PROXIES | ip() | is_secure | scheme |
|---|---|---|---|
| none | ALB内部IP | false | http |
| all / VPC CIDR | 実クライアントIP | true | https |

| TRUST_HOSTS | 正当Host（`*.elb.amazonaws.com`） | 不正Host（`evil.example`） |
|---|---|---|
| （空） | 200 | 200 |
| `^.*\.elb\.amazonaws\.com$` | 200 | 400 |

## 検証コマンドと期待出力

`ALB=$(terraform output -raw alb_url)` として、すべて `curl -k`（自己署名のため）。

### TrustProxies OFF（`.env`: `TRUST_PROXIES=none`）
```bash
curl -sk "$ALB/whoami" | jq
```
- `resolved.ip` = ALBの内部IP（`10.0.x.x`）
- `resolved.is_secure` = `false`、`resolved.scheme` = `http`、`resolved.full_url` は `http://` 始まり
- `raw.X-Forwarded-Proto` = `https`（ALBは付けているが信頼していないため解決値には反映されない）

### TrustProxies ON（`.env`: `TRUST_PROXIES=all`、reload後）
```bash
curl -sk "$ALB/whoami" | jq
```
- `resolved.ip` = 実クライアントIP
- `resolved.is_secure` = `true`、`resolved.scheme` = `https`、`resolved.full_url` は `https://` 始まり

### POST
```bash
curl -sk -X POST "$ALB/whoami" | jq '.method'   # => "POST"（CSRFで419にならない）
```

### TrustHosts（`.env`: `TRUST_HOSTS=^.*\.elb\.amazonaws\.com$`、reload後）
```bash
curl -sk -o /dev/null -w "%{http_code}\n" "$ALB/whoami"                        # => 200
curl -sk -o /dev/null -w "%{http_code}\n" "$ALB/whoami" -H "Host: evil.example" # => 400
```
`TRUST_HOSTS=`（空）に戻すと両方 `200` になる。

## なぜ `config:cache` を使ってはいけないか

`php artisan config:cache` を実行すると、Laravelは以降 `.env` を読み込まずキャッシュされたconfig値だけを参照するようになる。`bootstrap/app.php` は `env('TRUST_PROXIES')` / `env('TRUST_HOSTS')` を**ミドルウェア登録時に直接**読んでいるため、config化されておらず、config:cacheしても更新はされない一方、`env()` 呼び出し自体はキャッシュ後 `null` を返すようになる（Laravelの既知の挙動）。結果として `.env` を書き換えても反映されなくなり、この検証環境の目的（envトグルだけで即座に挙動を切り替えて観測すること）が成立しなくなる。そのため本リポジトリでは **`config:cache` を一切使用しない**（`route:cache` / `view:cache` は影響を受けないため使用可）。

## `bootstrap/app.php` で `.env` を明示的に読み込んでいる理由

`withMiddleware()` に渡したコールバックは、HTTPカーネル（`HttpKernel::class`）がコンテナから解決されたタイミングで実行される。これは `LoadEnvironmentVariables` ブートストラッパー（`.env` を実際に読み込む処理）より**前**に発生するため、対策をしないとコールバック内の `env('TRUST_PROXIES')` / `env('TRUST_HOSTS')` は常に `.env` の内容を無視してデフォルト値（`none` / 空文字）を返してしまう（実機検証で確認済みの挙動）。これを避けるため `bootstrap/app.php` の冒頭で `Dotenv\Dotenv::createImmutable(...)->safeLoad()` を呼び、`.env` を先に読み込んでいる。後続の通常のブートストラップ処理（`LoadEnvironmentVariables`）は `createImmutable`（既存値を上書きしない）のため二重読み込みしても副作用はない。
