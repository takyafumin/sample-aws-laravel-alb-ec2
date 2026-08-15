# TrustProxies / TrustHosts 解説

> このドキュメントはセキュリティの予備知識がなくても読めるように書いている。「HTTPヘッダは誰でも自由に書き換えられる」という前提さえ押さえれば、あとの話はすべてその応用。

## そもそも何が問題なのか（前提知識）

`X-Forwarded-For`・`X-Forwarded-Proto`・`Host` はどれも**ただのHTTPヘッダ**であり、リクエストを送る側（クライアント）が自由に書ける文字列にすぎない。

```bash
# 誰でもこう書ける。サーバー側で何も検証しなければ、これがそのまま信用されてしまう
curl http://example.com/ -H "X-Forwarded-For: 1.2.3.4"
```

ALBのような信頼できるロードバランサを経由したときだけ、こうしたヘッダを「本物」として扱いたい。しかしEC2（アプリサーバー）から見ると、ALB経由のリクエストも、悪意ある第三者が直接EC2を叩いて偽ヘッダを送ってきたリクエストも、**HTTPの見た目としては区別がつかない**。だからこそLaravelは既定で「直接つながってきた相手（＝ALBの内部IP）以外は何も信じない」という安全側に倒した挙動になっており、`trustProxies()`/`trustHosts()`で「どこまでなら信じてよいか」を明示的に指定する設計になっている。

この検証環境は、その「信じる／信じない」を切り替えたときに実際に何が起きるかを目で見て理解するためのものである。

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

### なぜ信頼先を絞る必要があるのか（具体例）

もしアプリがIPアドレスで管理画面へのアクセス制限（例:「社内IP `203.0.113.0/24` からのみ許可」）をしていたとする。`ip()` の値を信頼して判定している場合、`TRUST_PROXIES` が適切に絞られていないと、攻撃者は

```
X-Forwarded-For: 203.0.113.1
```

のようなヘッダを自分で付けて送るだけで、`ip()` の戻り値を偽装し、この制限を突破できてしまう。同様に、アクセスログやレート制限も `ip()` を元にしていることが多く、偽装できれば「誰の操作か分からなくする（ログ改ざん）」「1つのIPからの大量アクセスをまるで多数のユーザーからのアクセスに見せかけてレート制限を回避する」といった悪用が可能になる。

> **⚠️ この検証環境の `TRUST_PROXIES=all`（`at: '*'`）は、挙動を分かりやすく見せるためにあえて「全プロキシを信頼」する設定にしている。実際のプロダクション環境でこれをそのまま使ってはいけない。** `at: '*'` は「直接つながってきた相手（＝REMOTE_ADDR）を信頼する」という意味になるため、もしEC2に直接アクセスできる経路が万一開いてしまえば（SG設定ミス等）、そのままヘッダ偽装が成立してしまう。本番では `at:` に**実際のロードバランサのIP/CIDRだけ**を明示的に指定するのが正しい使い方（このリポジトリでいえば `TRUST_PROXIES=10.0.0.0/16` のようにVPC内のCIDRへ絞る、が実運用に近い設定）。

## TrustHosts が変えるもの

`trustHosts()` を設定すると、リクエストの `Host` ヘッダが許可パターンにマッチしない場合、Laravelはリクエストを **400 Bad Request** で拒否する。ホストヘッダ偽装（後述）対策。

このリポジトリでは `env('TRUST_HOSTS')`（カンマ区切りの正規表現）で切り替える。空文字なら `trustHosts()` を呼ばず、全Hostを許可する。

> **補足（正確性のため）**: 一般にLaravelの `TrustHosts` は、`TrustProxies` 側で `X-Forwarded-Host` を信頼する設定（`HEADER_X_FORWARDED_HOST` ビット）になっていれば、そのヘッダも判定対象にする。しかし本リポジトリの `bootstrap/app.php` が使う `Request::HEADER_X_FORWARDED_AWS_ELB` はこのビットを**含んでいない**ため、`TrustHosts` は常に生の `Host` ヘッダだけを見る。
>
> **補足（ハマりどころ）**: `TrustHosts` は `APP_ENV=local` のときはLaravel側で自動的にスキップされる（`shouldSpecifyTrustedHosts()`）。ローカルで `TRUST_HOSTS` を試すときに「設定したのに400にならない」と思ったら、まず `.env` の `APP_ENV` を確認すること。本リポジトリの既定値は `APP_ENV=production` なので、ローカルでも通常は問題なく動く。

### なぜHostヘッダの検証が必要なのか（具体例）

`Host` ヘッダもクライアントが自由に指定できる文字列である。Laravelの `url()` / `route()` 等のヘルパーは、絶対URLを組み立てる際にこの `Host`（正確には `getHost()`）を使う。もし検証していないと、たとえば「パスワードリセット用のリンクをメールで送る」機能で

```
リンク: https://<$request->getHost()>/reset-password?token=xxxxx
```

のようなURLを組み立てている場合、攻撃者が偽の `Host: attacker.example` ヘッダを付けてパスワードリセットをリクエストするだけで、正規ユーザー宛のメールに**攻撃者のドメインへのリンク**が埋め込まれてしまう（Host Header Injection）。ユーザーがそのリンクを踏むと、トークンが攻撃者のサーバーに送信され、アカウントを乗っ取られる。`TRUST_HOSTS` で許可するHostパターンを絞ることは、この手の攻撃を機械的にブロックする防御になる。

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

`ALB=$(terraform -chdir=infra output -raw alb_url)` として、すべて `curl -k`（自己署名のため）。EC2/ALBを構築する前にローカルで挙動だけ確認したい場合は、`php artisan whoami:check` / `php artisan env:set` を使うと `php artisan serve` やcurlを使わずに同じ確認ができる（README「artisanコマンドでの確認」参照）。

`.env` の書き換えはEC2側（SSMで入った先）、curlは手元のターミナルで実行する。手順の全体像・コピペ用コマンドはREADME「6. 検証の回し方」を参照。ここでは各状態での期待結果のみ示す。

### TrustProxies OFF（`.env`: `TRUST_PROXIES=none`、初期値）
```bash
curl -sk "$ALB/whoami" | jq
```
- `resolved.ip` = ALBの内部IP（`10.0.x.x`）
- `resolved.is_secure` = `false`、`resolved.scheme` = `http`、`resolved.full_url` は `http://` 始まり
- `raw.X-Forwarded-Proto` = `https`（ALBは付けているが信頼していないため解決値には反映されない）

### TrustProxies ON（`.env`: `TRUST_PROXIES=all`、reload後）

EC2側:
```bash
sudo sed -i 's/^TRUST_PROXIES=none/TRUST_PROXIES=all/' /var/www/app/.env
sudo systemctl reload apache2
```
手元:
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

EC2側:
```bash
sudo sed -i 's/^TRUST_HOSTS=.*/TRUST_HOSTS=^.*\\.elb\\.amazonaws\\.com$/' /var/www/app/.env
sudo systemctl reload apache2
```
手元:
```bash
curl -sk -o /dev/null -w "%{http_code}\n" "$ALB/whoami"                        # => 200
curl -sk -o /dev/null -w "%{http_code}\n" "$ALB/whoami" -H "Host: evil.example" # => 400
```
`TRUST_HOSTS=`（空、EC2側で `sudo sed -i 's/^TRUST_HOSTS=.*/TRUST_HOSTS=/' /var/www/app/.env` してreload）に戻すと両方 `200` になる。

## なぜ `config:cache` を使ってはいけないか

`php artisan config:cache` を実行すると、Laravelは以降 `.env` を読み込まずキャッシュされたconfig値だけを参照するようになる。`bootstrap/app.php` は `env('TRUST_PROXIES')` / `env('TRUST_HOSTS')` を**ミドルウェア登録時に直接**読んでいるため、config化されておらず、config:cacheしても更新はされない一方、`env()` 呼び出し自体はキャッシュ後 `null` を返すようになる（Laravelの既知の挙動）。結果として `.env` を書き換えても反映されなくなり、この検証環境の目的（envトグルだけで即座に挙動を切り替えて観測すること）が成立しなくなる。そのため本リポジトリでは **`config:cache` を一切使用しない**（`route:cache` / `view:cache` は影響を受けないため使用可）。

## `bootstrap/app.php` で `.env` を明示的に読み込んでいる理由

`withMiddleware()` に渡したコールバックは、HTTPカーネル（`HttpKernel::class`）がコンテナから解決されたタイミングで実行される。これは `LoadEnvironmentVariables` ブートストラッパー（`.env` を実際に読み込む処理）より**前**に発生するため、対策をしないとコールバック内の `env('TRUST_PROXIES')` / `env('TRUST_HOSTS')` は常に `.env` の内容を無視してデフォルト値（`none` / 空文字）を返してしまう（実機検証で確認済みの挙動）。これを避けるため `bootstrap/app.php` の冒頭で `Dotenv\Dotenv::createImmutable(...)->safeLoad()` を呼び、`.env` を先に読み込んでいる。後続の通常のブートストラップ処理（`LoadEnvironmentVariables`）は `createImmutable`（既存値を上書きしない）のため二重読み込みしても副作用はない。
