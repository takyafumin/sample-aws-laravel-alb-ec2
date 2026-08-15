# Laravel on AWS (ALB + EC2) — TrustProxy / TrustHost 検証環境

ALB + 単一EC2上でLaravel 13を動かし、**TrustProxies / TrustHosts の挙動差分を実際に観測できる**検証環境。`.env` のトグルを書き換えるだけで、ALBが付与するプロキシヘッダ（`X-Forwarded-For` / `X-Forwarded-Proto` / `Host` など）をLaravelが信頼する・しないの違いを `GET/POST /whoami` のレスポンスとログで確認できる。

高可用・オートスケール・独自ドメイン・外部デプロイツール・永続DBは非ゴール（詳細は [GENERATION_SPEC.md](GENERATION_SPEC.md) 参照）。

## 1. 前提ツール

- AWS CLI（`aws sts get-caller-identity` が通る状態、対象リージョンは既定 `ap-northeast-1`）
- Terraform >= 1.11（S3ネイティブロック `use_lockfile` を使うため）
- [mise](https://mise.jdx.dev/)
- git

## 2. ローカルセットアップ

```bash
mise install
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

`http://127.0.0.1:8000/whoami` にアクセスして動作確認できる（ローカルではALB由来のヘッダが無いため `raw.*` はほぼ空になる）。

### artisanコマンドでの確認（curl不要）

`php artisan serve` を起動しなくても、実際のHTTPカーネル（ミドルウェアスタック一式）を通して `/whoami` を叩けるコマンドを同梱している。`.env` の書き換えも `env:set` で行える。

```bash
# TRUST_PROXIES=none のまま、ALBヘッダを模してリクエスト
php artisan whoami:check --for=203.0.113.9 --proto=https
# => resolved.ip は自分の接続元IPのまま、is_secure=false（ヘッダは無視される）

# TRUST_PROXIES=all に切替
php artisan env:set TRUST_PROXIES all
php artisan whoami:check --for=203.0.113.9 --proto=https
# => resolved.ip=203.0.113.9, is_secure=true, scheme=https に反転

# POST の確認（CSRFで419にならないこと）
php artisan whoami:check --method=POST

# TRUST_HOSTS の確認
php artisan env:set TRUST_HOSTS '^.*\.elb\.amazonaws\.com$'
php artisan whoami:check --host=my-alb.ap-northeast-1.elb.amazonaws.com   # => 200
php artisan whoami:check --host=evil.example                              # => 400

# 既定値に戻す
php artisan env:set TRUST_PROXIES none
php artisan env:set TRUST_HOSTS
```

`storage/logs/laravel.log` に `LogConnection` ミドルウェアの出力（`/whoami` と同じ項目）が記録されるので、`tail -f storage/logs/laravel.log` と併用するとよい。

## 3. Stateバケット作成

```bash
bash scripts/bootstrap_state.sh <bucket-name> ap-northeast-1
```

既にバケットが存在してもエラーにならない（冪等）。バージョニングとパブリックアクセスブロックを有効化する。

## 4. インフラ構築

```bash
cd infra
cp backend.hcl.example backend.hcl   # bucket名を編集
terraform init -backend-config=backend.hcl
terraform apply -var 'git_repo_url=https://github.com/you/this-repo.git'
```

## 5. 動作確認

```bash
terraform output alb_url
```

EC2の初期プロビジョニング（`user_data`）が完了して `/up` が200を返すまで、ALBのターゲットグループは `unhealthy` のまま。**数分待ってから**、11章相当の受け入れ条件を [docs/trust-proxy.md](docs/trust-proxy.md) のcurlコマンドで確認する。

## 6. 検証の回し方

1. SSMでEC2に入る: `aws ssm start-session --target $(terraform -chdir=infra output -raw instance_id)`
2. `/var/www/app/.env` の `TRUST_PROXIES` / `TRUST_HOSTS` を編集
3. `sudo systemctl reload apache2`

`config:cache` を実行していないため、`.env` の変更はApache reloadだけで即座に反映される（**`php artisan config:cache` は絶対に実行しないこと**。理由は [docs/trust-proxy.md](docs/trust-proxy.md)）。

## 7. 更新デプロイ

コード変更後の更新は SSM 経由で `scripts/deploy.sh` を実行する。詳細は [docs/deploy.md](docs/deploy.md)。

```bash
# 対話
aws ssm start-session --target <instance_id>
sudo bash /var/www/app/scripts/deploy.sh

# 非対話
aws ssm send-command --document-name "AWS-RunShellScript" \
  --targets "Key=instanceids,Values=<instance_id>" \
  --parameters 'commands=["sudo bash /var/www/app/scripts/deploy.sh"]'
```

## 8. 後片付け

```bash
cd infra
terraform destroy
```

state バケット自体を消す場合（任意）:
```bash
aws s3 rm s3://<bucket-name> --recursive
aws s3api delete-bucket --bucket <bucket-name> --region ap-northeast-1
```

## 9. トラブルシュート

- **ターゲットグループが unhealthy のまま**: `user_data` によるプロビジョニングが完了していない可能性が高い（数分かかる）。SSMで入り `sudo tail -f /var/log/user-data.log` で進捗を確認する。
- **`curl` で 400 が返る**: `TRUST_HOSTS` が設定されている状態で、リクエストの `Host` ヘッダがそのパターンにマッチしていない（意図した挙動。[docs/trust-proxy.md](docs/trust-proxy.md) 参照）。
- **SSMで入れない**: EC2にアタッチされたインスタンスプロファイル（`AmazonSSMManagedInstanceCore`）と、SSM Agentが起動しているか（`systemctl status snap.amazon-ssm-agent.amazon-ssm-agent.service`）を確認する。22番ポートは意図的に開けていないためSSHでは入れない。

## PHPバージョンを8.5に変更する場合

`.mise.toml` の `php = "8.4"` と、`infra/user_data.sh.tpl` 内の `PHP_VERSION=8.4`（ondrej/php PPAパッケージ名に使われる）の**両方**を変更すること。片方だけ変更するとローカルとEC2でPHPバージョンがずれる。

## リポジトリ構成

```
.
├─ app/                # WhoAmIController, LogConnection
├─ bootstrap/app.php    # trustProxies / trustHosts / CSRF除外 / ミドルウェア登録
├─ routes/web.php       # GET/POST /whoami, /up はLaravel標準
├─ infra/                # Terraform一式
├─ scripts/              # bootstrap_state.sh, deploy.sh
└─ docs/                 # architecture.md, trust-proxy.md, deploy.md
```

## 12. 生成後、人間が実行する順

```bash
mise install
bash scripts/bootstrap_state.sh my-tf-state-bucket ap-northeast-1
cd infra
cp backend.hcl.example backend.hcl   # bucket名を記入
terraform init -backend-config=backend.hcl
terraform apply -var 'git_repo_url=https://github.com/you/this-repo.git'
terraform output alb_url
# 数分待って /up が healthy になったら docs/trust-proxy.md のcurlで検証
```
