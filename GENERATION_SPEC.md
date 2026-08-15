# Laravel on AWS (ALB + EC2) — TrustProxy / TrustHost 検証環境 生成指示書

> この文書は「リポジトリ一式を自動生成させるための仕様（プロンプト）」です。
> 生成AI／エージェントにこのファイルをそのまま渡し、「このSPECに従ってリポジトリを生成して」と指示してください。
> 章立ての順に実装し、最後の「受け入れ条件」を満たすこと。曖昧な箇所は本SPECの既定値に従うこと。

---

## 0. この指示書の使い方

- 出力は **1つのGitリポジトリ**（下記ファイルツリー）。
- すべて **冪等** に動くこと：`terraform apply` は複数回実行しても差分ゼロに収束、プロビジョニングは再実行可能。
- コード内のコメントとドキュメントは日本語で可。識別子・パス・コマンドは英語。
- 秘密情報（AWSキー等）はコードに埋め込まない。リージョン等は variables 化。

---

## 1. ゴール / 非ゴール

### ゴール
- ALB + 単一 EC2 上で Laravel を動かし、**TrustProxies / TrustHosts の挙動差分を観測できる**こと。
- `GET /whoami` と `POST /whoami` が、**受信した生ヘッダ**と**Laravelが解決した値**の両方を返す・ログ出力する。
- ローカルは mise で PHP/Node を固定。インフラは Terraform（state は S3 固定）。
- README で使い方、docs で設計を説明。

### 非ゴール（作らない）
- 高可用・オートスケール・本番運用（単一EC2、ASGなし）。
- 独自ドメイン / Route53 / 公開ACM証明書（**自己署名証明書**でHTTPS化する）。
- Deployer 等の外部デプロイツール（更新は SSM 経由の `git pull`）。
- 実データ・永続DB（**SQLite** をローカルファイルで使用、検証用）。

---

## 2. 確定した技術選定（前提）

| 項目 | 値 |
|---|---|
| リージョン | `ap-northeast-1`（variableで変更可） |
| ALB TLS | **HTTPSリスナー + 自己署名証明書をACMにimport**。`curl -k` で叩く |
| ALB→EC2 | HTTP:80 で転送（TLSはALBで終端）。これにより `X-Forwarded-Proto: https` が流れる |
| Webサーバ | **Apache** + mod_php（EC2上） |
| OS/AMI | **Ubuntu 24.04 LTS**（ondrej/php PPA で PHP を固定） |
| PHP | **8.4**（既定。`.env`/PPAで 8.5 に差し替え可） |
| Laravel | **13.x**（最新安定） |
| Node | **24 (LTS)** |
| DB | SQLite（`database/database.sqlite`） |
| EC2アクセス | **SSM Session Manager**（22番は開けない） |
| Terraform state | **S3**（AWS CLI で先にバケット作成）。ロックは Terraform>=1.11 の S3ネイティブロック（`use_lockfile`）。DynamoDBは使わない |
| 初期コード配布 | EC2 の user_data で `git clone` + プロビジョニング |
| 更新デプロイ | SSM で入って `git pull`（`scripts/deploy.sh`、`aws ssm send-command` でも可） |

---

## 3. 生成する成果物（ファイルツリー）

```
.
├─ app/
│  ├─ Http/
│  │  ├─ Controllers/WhoAmIController.php
│  │  └─ Middleware/LogConnection.php
├─ bootstrap/app.php                 # ★ trustProxies / trustHosts / CSRF除外 / ミドルウェア登録
├─ routes/web.php                    # GET/POST /whoami, /up は Laravel標準
├─ config/                           # Laravel標準一式
├─ database/
│  └─ .gitignore                     # database.sqlite は追跡しない（プロビジョニングで生成）
├─ public/index.php
├─ .env.example                      # 検証用トグル込み
├─ .mise.toml                        # php/node/composer 固定
├─ composer.json / composer.lock
├─ infra/
│  ├─ backend.tf                     # S3 backend
│  ├─ backend.hcl.example            # bucket名等を渡す
│  ├─ providers.tf                   # aws, tls provider
│  ├─ versions.tf                    # required_version >= 1.11, provider制約
│  ├─ variables.tf
│  ├─ network.tf                     # VPC/subnet(2AZ)/IGW/route
│  ├─ security.tf                    # SG(ALB, EC2)
│  ├─ cert.tf                        # 自己署名生成 + ACM import
│  ├─ alb.tf                         # ALB/TG(/up health)/listener(443 forward, 80→443 redirect)
│  ├─ iam.tf                         # SSM用インスタンスプロファイル
│  ├─ ec2.tf                         # Ubuntu AMI(data), instance, user_data
│  ├─ user_data.sh.tpl              # 初期プロビジョニング
│  └─ outputs.tf                     # alb_dns_name, instance_id など
├─ scripts/
│  ├─ bootstrap_state.sh             # S3バケット作成（冪等）
│  └─ deploy.sh                      # EC2上で実行する更新スクリプト
├─ README.md
└─ docs/
   ├─ architecture.md
   ├─ trust-proxy.md
   └─ deploy.md
```

---

## 4. バージョン固定

`.mise.toml`:
```toml
[tools]
php = "8.4"
node = "24"
composer = "latest"
```

- user_data 側の PHP も **同じ 8.4 系**を ondrej/php PPA から入れ、ローカルと一致させること。
- README に「8.5 にする場合は `.mise.toml` と `user_data.sh.tpl` の PPA パッケージ名を両方変える」旨を記載。

---

## 5. アプリケーション仕様

### 5.1 ルート（`routes/web.php`）
- `GET /whoami` → `WhoAmIController@show`
- `POST /whoami` → `WhoAmIController@show`（**CSRF除外**）
- `/up` は Laravel 標準のヘルスチェックをそのまま使う（ALBのTGヘルスチェックに使用）。

### 5.2 診断フィールド（レスポンスJSON & ログの中身）
`WhoAmIController@show` は以下を返す。**生ヘッダ**と**Laravel解決値**を並べること。

```jsonc
{
  "raw": {
    "REMOTE_ADDR": "...",              // $request->server('REMOTE_ADDR')  … 直近peer=ALBの内部IP
    "X-Forwarded-For": "...",          // $request->header('X-Forwarded-For')
    "X-Forwarded-Proto": "...",
    "X-Forwarded-Host": "...",
    "X-Forwarded-Port": "...",
    "Host": "..."                       // $request->header('Host')
  },
  "resolved": {
    "ip": "...",                        // $request->ip()
    "ips": ["..."],                     // $request->ips()
    "host": "...",                      // $request->getHost()
    "http_host": "...",                 // $request->getHttpHost()
    "is_secure": true,                  // $request->isSecure()
    "scheme": "https",                  // $request->getScheme()
    "port": 443,                        // $request->getPort()
    "full_url": "https://.../whoami"    // $request->fullUrl()
  },
  "trust_config": {
    "TRUST_PROXIES": "...",             // env('TRUST_PROXIES')  … 今どのモードか自己申告
    "TRUST_HOSTS": "..."               // env('TRUST_HOSTS')
  },
  "method": "GET"
}
```

### 5.3 Trust ミドルウェア（`bootstrap/app.php`）★最重要
- **env で挙動をトグル**できるようにする。`.env` 書き換えだけで切替可能にすること。
- ALB 用に `Request::HEADER_X_FORWARDED_AWS_ELB` ビットマスクを使う。

```php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
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
        //
    })->create();
```

### 5.4 ログ（`app/Http/Middleware/LogConnection.php`）
- 毎リクエストで `Log::info('connection', [...])` に 5.2 と同じ項目を出力。
- ログは `storage/logs/laravel.log`（`single`/`daily` どちらでも可）。SSMで入って `tail -f` する前提。
- 例：`[2026-08-15 ...] production.INFO: connection {"REMOTE_ADDR":"10.0.1.23","X-Forwarded-For":"203.0.113.9",...,"ip":"203.0.113.9","is_secure":true}`

### 5.5 config キャッシュの注意（★ドキュメント必須）
- **この検証機では `php artisan config:cache` を使わない。**
- 理由：config をキャッシュすると Laravel は `.env` を読み込まなくなり、`bootstrap/app.php` の `env('TRUST_PROXIES')` 等が `null` になりトグルが効かなくなる。
- README/docs と `.env.example` コメントに明記。プロビジョニングでも config:cache は実行しないこと（`route:cache` / `view:cache` は可）。

### 5.6 `.env.example`（検証用トグル）
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=                     # プロビジョニングで php artisan key:generate
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=sqlite
# DB_DATABASE は絶対パスをプロビジョニングで設定（database/database.sqlite）

# ---- 検証トグル（ここを書き換えて挙動を比較する。config:cache は使わないこと）----
# TRUST_PROXIES: none | all | 10.0.0.0/16 のようなCIDRカンマ区切り
TRUST_PROXIES=none
# TRUST_HOSTS: カンマ区切りの正規表現（空=全Host許可）。例: ^.*\.elb\.amazonaws\.com$
TRUST_HOSTS=
```

---

## 6. インフラ仕様（Terraform / `infra/`）

### 6.1 backend & state ブートストラップ
- `versions.tf`：`required_version >= 1.11`（S3ネイティブロック用）。aws provider `~> 5.x`（生成時の最新メジャーに追随可）、tls provider。
- `backend.tf`：
  ```hcl
  terraform {
    backend "s3" {
      # bucket / key / region は backend.hcl から渡す
      encrypt      = true
      use_lockfile = true   # DynamoDB不要のネイティブロック
    }
  }
  ```
- `backend.hcl.example`：
  ```hcl
  bucket = "REPLACE-ME-tf-state-bucket"
  key    = "trust-verify/terraform.tfstate"
  region = "ap-northeast-1"
  ```
- `scripts/bootstrap_state.sh`（**冪等**：既存でもエラーで止めない。バージョニング有効化）：
  ```bash
  #!/usr/bin/env bash
  set -euo pipefail
  BUCKET="${1:?usage: bootstrap_state.sh <bucket-name> [region]}"
  REGION="${2:-ap-northeast-1}"
  if aws s3api head-bucket --bucket "$BUCKET" 2>/dev/null; then
    echo "bucket exists: $BUCKET"
  else
    aws s3api create-bucket --bucket "$BUCKET" --region "$REGION" \
      --create-bucket-configuration LocationConstraint="$REGION"
  fi
  aws s3api put-bucket-versioning --bucket "$BUCKET" \
    --versioning-configuration Status=Enabled
  aws s3api put-public-access-block --bucket "$BUCKET" \
    --public-access-block-configuration BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true
  echo "state bucket ready: $BUCKET"
  ```

### 6.2 リソース一覧
- **network.tf**：VPC(`10.0.0.0/16`)、**2つのパブリックサブネット（別AZ）**、IGW、ルートテーブル(0.0.0.0/0→IGW)、関連付け。（ALBは最低2AZ必要）
- **security.tf**：
  - ALB用SG：inbound 443（0.0.0.0/0）、必要なら80（redirect用）、egress all。
  - EC2用SG：inbound **80 を ALB SG からのみ**、egress all。**22は開けない**。
- **cert.tf**：`tls_private_key` + `tls_self_signed_cert`（CNは任意、`curl -k` 前提）→ `aws_acm_certificate`（`private_key`/`certificate_body` で import）。
- **alb.tf**：
  - `aws_lb`（application、2サブネット、ALB SG）。
  - `aws_lb_target_group`（protocol HTTP、port 80、health_check path=`/up`、matcher 200、`target_type=instance`）。
  - `aws_lb_target_group_attachment`（EC2をアタッチ）。
  - listener 443（protocol HTTPS、`ssl_policy` 標準、`certificate_arn`=import証明書、default action forward）。
  - listener 80（default action redirect→443）。任意だが利便性のため作る。
- **iam.tf**：ロール（`ec2.amazonaws.com` 信頼）+ `AmazonSSMManagedInstanceCore` アタッチ + インスタンスプロファイル。
- **ec2.tf**：
  - `data aws_ami`（Canonical公式 Ubuntu 24.04、amd64、最新）。
  - `aws_instance`（`t3.micro`、パブリックサブネット、`associate_public_ip_address=true`、EC2 SG、インスタンスプロファイル、`user_data` に `user_data.sh.tpl` を `templatefile()` で埋め込み）。
  - user_data には **Gitリポジトリ URL** を variable で渡す。
- **variables.tf**：`region`(default ap-northeast-1)、`instance_type`(default t3.micro)、`git_repo_url`、`git_branch`(default main)、`project_name`(タグ用) など。
- **outputs.tf**：`alb_dns_name`、`alb_url`(=`https://<dns>`)、`instance_id`（SSM用）、`vpc_id`。

---

## 7. 初期プロビジョニング（`infra/user_data.sh.tpl`）

EC2起動時に一度だけ実行。**再実行しても壊れない**書き方（存在チェック）にする。

やること:
1. SSMエージェントが有効か確認（Ubuntu公式AMIはsnapで同梱。無ければ `snap install amazon-ssm-agent` して enable）。
2. `apt-get update` → 必要パッケージ、`software-properties-common`、ondrej/php PPA 追加。
3. `apache2`、`php8.4`、`libapache2-mod-php8.4`、`php8.4-{sqlite3,mbstring,xml,curl,bcmath,intl,zip}`、`git`、`unzip`、Composer をインストール。
4. `git clone <git_repo_url> -b <git_branch> /var/www/app`（既存ならpull）。
5. `composer install --no-dev --optimize-autoloader`。
6. `.env` を作成（`.env.example` ベース）：`DB_CONNECTION=sqlite`、`DB_DATABASE=/var/www/app/database/database.sqlite`（絶対パス）、`touch` でsqlite作成、`php artisan key:generate`。
7. `php artisan migrate --force`。
8. **config:cache はしない**（5.5参照）。`php artisan route:cache` は任意。
9. Apache vhost：DocumentRoot=`/var/www/app/public`、`AllowOverride All`、`a2enmod rewrite`、`a2enmod remoteip` は使わず（Laravel側でXFF処理するため不要）。所有権 `www-data`、`storage`/`bootstrap/cache` を書込可能に。
10. `systemctl enable --now apache2`、`a2dissite 000-default`、新vhostを有効化、`systemctl reload apache2`。

> 注意：ALBのTGヘルスチェックは `/up`。Apacheが80で起動し `/up` が200を返すまでALBは `unhealthy`。プロビジョニング完了まで数分かかる旨を docs に記載。

---

## 8. デプロイ更新（SSM経由）

`scripts/deploy.sh`（EC2上で実行）:
```bash
#!/usr/bin/env bash
set -euo pipefail
cd /var/www/app
sudo -u www-data git pull --ff-only
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan route:clear
sudo systemctl reload apache2
echo "deployed: $(git rev-parse --short HEAD)"
```

README に2通り記載:
- 対話：`aws ssm start-session --target <instance_id>` → `sudo bash /var/www/app/scripts/deploy.sh`
- 非対話：`aws ssm send-command --document-name "AWS-RunShellScript" --targets "Key=instanceids,Values=<instance_id>" --parameters 'commands=["sudo bash /var/www/app/scripts/deploy.sh"]'`

> privateリポジトリの場合：deploy key（読み取り専用）の設置手順を docs/deploy.md に。user_data では公開リポジトリ前提。

---

## 9. README.md 目次
1. 概要 / このリポジトリで検証できること（TrustProxy/TrustHostの一言説明）
2. 前提ツール：AWS CLI（認証済）、Terraform >= 1.11、mise、git
3. ローカルセットアップ：`mise install` → `composer install` → `.env` → `php artisan serve`
4. Stateバケット作成：`bash scripts/bootstrap_state.sh <bucket>`
5. インフラ構築：`cp backend.hcl.example backend.hcl`（編集）→ `terraform init -backend-config=backend.hcl` → `terraform apply -var git_repo_url=...`
6. 動作確認：`terraform output alb_url` → 数分待つ → 受け入れ条件（11章）のcurl
7. 検証の回し方：`.env` の `TRUST_PROXIES`/`TRUST_HOSTS` を SSM で書き換え→ `apache2 reload`（config:cacheしていないので即反映）
8. 更新デプロイ：8章
9. 後片付け：`terraform destroy` → 必要なら state バケット削除手順
10. トラブルシュート：TGがunhealthy／curlで400が出る意味／SSMで入れない時の確認（インスタンスプロファイル・SSMエージェント）

## 10. docs 目次
- **architecture.md**：構成図（Mermaidで client→ALB(TLS終端,自己署名)→EC2:80 Apache+mod_php→SQLite）、なぜ自己署名HTTPSか（schemeフリップ観測のため）、コスト概算、パブリックサブネット採用理由（NAT回避）、privateサブネット化する場合のVPCエンドポイント3種（ssm/ssmmessages/ec2messages）を付録。
- **trust-proxy.md**：TrustProxies と TrustHosts が何を変えるかの解説＋**真理値表**（11章）＋各curlコマンドと期待出力。config:cache禁止の理由も。
- **deploy.md**：初期プロビジョニング（user_data）と更新フロー（SSM）、privateリポのdeploy key手順。

---

## 11. 受け入れ条件（これを満たせば完成）

`ALB=$(terraform output -raw alb_url)` とする。すべて `curl -k`（自己署名のため）。

### 11.1 TrustProxies OFF（`.env`: `TRUST_PROXIES=none`）
```bash
curl -sk "$ALB/whoami" | jq
```
期待:
- `resolved.ip` = **ALBの内部IP（10.0.x.x）**（実クライアントIPではない）
- `resolved.is_secure` = **false**、`resolved.scheme` = **http**、`resolved.full_url` が **http://** 始まり
- `raw.X-Forwarded-Proto` = `https`（ALBは付けているが、信頼していないので解決値に反映されない）

### 11.2 TrustProxies ON（`.env`: `TRUST_PROXIES=all`、reload後）
```bash
curl -sk "$ALB/whoami" | jq
```
期待:
- `resolved.ip` = **自分の実クライアントIP**
- `resolved.is_secure` = **true**、`resolved.scheme` = **https**、`resolved.full_url` が **https://** 始まり
- → これが「ALBのX-Forwarded-Protoを信頼した」ことの証明（手動注入なしで観測）

### 11.3 POST も同様に動く
```bash
curl -sk -X POST "$ALB/whoami" | jq '.method'   # => "POST"（CSRFで419にならない）
```

### 11.4 TrustHosts（`.env`: `TRUST_HOSTS=^.*\.elb\.amazonaws\.com$`、reload後）
```bash
curl -sk -o /dev/null -w "%{http_code}\n" "$ALB/whoami"                       # => 200
curl -sk -o /dev/null -w "%{http_code}\n" "$ALB/whoami" -H "Host: evil.example" # => 400
```
`TRUST_HOSTS=`（空）に戻すと両方 **200**。

### 11.5 真理値表（docs/trust-proxy.md に記載）
| TRUST_PROXIES | ip() | is_secure | scheme |
|---|---|---|---|
| none | ALB内部IP | false | http |
| all / VPC CIDR | 実クライアントIP | true | https |

| TRUST_HOSTS | 正当Host | 不正Host |
|---|---|---|
| （空） | 200 | 200 |
| ALBパターン | 200 | 400 |

### 11.6 冪等性
- `terraform apply` 再実行で **No changes**。
- `bootstrap_state.sh` 再実行でエラーなく完了。

---

## 12. 生成後、人間が実行する順（READMEにも記載）
```bash
mise install
bash scripts/bootstrap_state.sh my-tf-state-bucket ap-northeast-1
cd infra
cp backend.hcl.example backend.hcl   # bucket名を記入
terraform init -backend-config=backend.hcl
terraform apply -var 'git_repo_url=https://github.com/you/this-repo.git'
terraform output alb_url
# 数分待って /up が healthy になったら 11章のcurlで検証
```

---

## 付録：既定値まとめ（曖昧な場合はこれに従う）
- region=ap-northeast-1 / instance_type=t3.micro / branch=main
- PHP 8.4 / Laravel 13 / Node 24 / Ubuntu 24.04 / Apache+mod_php
- state: S3 + ネイティブロック（DynamoDBなし）/ EC2アクセス: SSMのみ（22閉）
- ALB: 443(HTTPS,自己署名) forward + 80 redirect、TG: HTTP:80 health=/up
- config:cache は使用禁止（envトグルのため）
