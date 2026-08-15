# Laravel on AWS (ALB + EC2) — TrustProxy / TrustHost 検証環境

ALB + 単一EC2上でLaravel 13を動かし、**TrustProxies / TrustHosts の挙動差分を実際に観測できる**検証環境。`.env` のトグルを書き換えるだけで、ALBが付与するプロキシヘッダ（`X-Forwarded-For` / `X-Forwarded-Proto` / `Host` など）をLaravelが信頼する・しないの違いを `GET/POST /whoami` のレスポンスとログで確認できる。

### このリポジトリで学べること

- **HTTPヘッダは偽装できる**という前提を、実際にヘッダを偽装してAPIの応答が変わる様子で体感できる（[docs/trust-proxy.md](docs/trust-proxy.md)）
- ロードバランサ配下のアプリで「信頼するプロキシ／Hostを明示的に絞る」ことがなぜ必要か（IPアドレス偽装・Hostヘッダインジェクションという具体的な攻撃と結びつけて理解する）
- 自己署名証明書・`curl -k`・SSH閉塞＋SSM運用など、検証環境ならではの割り切りと、本番ではどうすべきかの違い（[docs/architecture.md](docs/architecture.md)）
- 最小権限IAM設計の実例（`PowerUserAccess` がなぜIAM操作を許可しないのか、実際にハマった過程込みで [README「IAM権限について」](#iam権限について)）

セキュリティの前提知識がなくても読めるように書いてあるので、各docsの「なぜ危険か」「なぜこの設計か」という補足から読むのがおすすめ。

高可用・オートスケール・独自ドメイン・外部デプロイツール・永続DBは非ゴール（詳細は [GENERATION_SPEC.md](GENERATION_SPEC.md) 参照）。

## 1. 前提ツール

- AWS CLI（`aws sts get-caller-identity` が通る状態、対象リージョンは既定 `ap-northeast-1`）
- Terraform >= 1.11（S3ネイティブロック `use_lockfile` を使うため）
- [mise](https://mise.jdx.dev/)
- git

### IAM権限について

`terraform apply` を実行する人（＝あなた自身のIAM Identity）には、VPC/ALB/EC2に加えて **IAMロール・インスタンスプロファイルを作成できる権限**が必要になる（EC2にSSM用のロールをアタッチするため。[infra/iam.tf](../infra/iam.tf)）。

**`PowerUserAccess` だけでは不足する。** これはAWSの意図的な設計で、`PowerUserAccess` ポリシーは `NotAction: ["iam:*", ...]` という形でIAM操作全般を明示的に除外している（「PowerUser経由で自分にIAMロールを作らせて権限昇格する」ことを防ぐため）。IAM Identity Center（SSO）を使っている場合、対象のPermission Setに以下のようなIAM操作を許可する追加ポリシーが必要になる:

`iam:CreateRole` / `iam:GetRole` / `iam:DeleteRole` / `iam:TagRole` / `iam:AttachRolePolicy` / `iam:DetachRolePolicy` / `iam:CreateInstanceProfile` / `iam:DeleteInstanceProfile` / `iam:AddRoleToInstanceProfile` / `iam:RemoveRoleFromInstanceProfile` / `iam:PassRole`

（`AmazonEC2FullAccess`や`IAMFullAccess`のような広いAWS管理ポリシーを追加でアタッチしても解決する。個人検証用アカウントであれば手軽さを優先してこちらでもよい。）

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

# POST の確認（CSRFで419にならないこと。理由は下記）
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

> **なぜ `/whoami` はCSRF保護を除外しているか**: CSRF（クロスサイトリクエストフォージェリ）保護は、ログイン中のユーザーが気づかないうちに別サイト経由で「意図しない状態変更（送金・退会・投稿など）」をさせられるのを防ぐ仕組み。`/whoami` は診断用の読み取り専用エンドポイントで、DBの状態を変えたりユーザーに不利益を与えたりする副作用が一切ないため、確認のしやすさを優先して除外している。**状態を変更する本物のPOSTエンドポイントに対して同じことをしてはいけない**（`bootstrap/app.php` の `validateCsrfTokens(except: [...])` に安易にルートを追加しないこと）。

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

EC2の初期プロビジョニング（`user_data`）が完了して `/up` が200を返すまで、ALBのターゲットグループは `unhealthy` のまま。**数分待ってから**、[docs/trust-proxy.md](docs/trust-proxy.md) のcurlコマンドで期待される挙動を確認する。

## 6. 検証の回し方

`.env` の編集は **EC2側**（SSMで入った先）、curlでの確認は **手元のターミナル**で行う。2箇所を行き来する点に注意。

```
[手元のターミナル] --curl(ALB経由)--> [EC2]
        ↑                                ↑
   結果を見る                    SSMで入って .env を編集
```

`config:cache` を実行していないため、`.env` の変更はApache reloadだけで即座に反映される（**`php artisan config:cache` は絶対に実行しないこと**。理由は [docs/trust-proxy.md](docs/trust-proxy.md)）。

### TRUST_PROXIES=all を試す

手元のターミナルで比較用に現状を記録しておく:
```bash
ALB=$(terraform -chdir=infra output -raw alb_url)
curl -sk "$ALB/whoami" | jq '.resolved'   # 今は ip=ALB内部IP, is_secure=false のはず
```

SSMでEC2に入り、`.env` を書き換えてreload:
```bash
aws ssm start-session --target $(terraform -chdir=infra output -raw instance_id)
```
入った先（EC2側）で:
```bash
sudo sed -i 's/^TRUST_PROXIES=none/TRUST_PROXIES=all/' /var/www/app/.env
sudo systemctl reload apache2
exit
```

手元のターミナルに戻ってもう一度curl:
```bash
curl -sk "$ALB/whoami" | jq '.resolved'   # ip=自分のグローバルIP, is_secure=true, scheme=https に変わる
```

### TRUST_HOSTS を試す

SSMで入って `.env` を編集:
```bash
aws ssm start-session --target $(terraform -chdir=infra output -raw instance_id)
```
```bash
sudo sed -i 's/^TRUST_HOSTS=.*/TRUST_HOSTS=^.*\\.elb\\.amazonaws\\.com$/' /var/www/app/.env
sudo systemctl reload apache2
exit
```

手元のターミナルで確認:
```bash
curl -sk -o /dev/null -w "%{http_code}\n" "$ALB/whoami"                        # => 200（正当なHost）
curl -sk -o /dev/null -w "%{http_code}\n" "$ALB/whoami" -H "Host: evil.example" # => 400（不正なHost）
```

### 既定値に戻す

検証が終わったらSSMで入り直し、既定値に戻しておく:
```bash
sudo sed -i 's/^TRUST_PROXIES=all/TRUST_PROXIES=none/' /var/www/app/.env
sudo sed -i 's/^TRUST_HOSTS=.*/TRUST_HOSTS=/' /var/www/app/.env
sudo systemctl reload apache2
```

真理値表・全体像は [docs/trust-proxy.md](docs/trust-proxy.md) を参照。

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
  - ログが途中で止まっていて `/var/www/app` が存在しない場合、`user_data` スクリプトがエラーで異常終了している（`set -euo pipefail` により最初のエラーで即停止する）。原因を`infra/user_data.sh.tpl`側で修正した上で、**同じインスタンスをただ再起動しても直らない**点に注意（cloud-initは同じインスタンスIDに対して`user_data`を一度しか実行しない）。修正後は `terraform apply -replace=aws_instance.app -var 'git_repo_url=...'` でインスタンスを作り直す。
  - `curl -i http://127.0.0.1/up` がEC2内で404を返す場合は、Apacheが `000-default` のままで自前のvhostに切り替わっていない＝`user_data`が9ステップ目まで到達していない証拠。
  - **`/var/log/apache2/trust-verify-access.log` に `"GET /up ..." 400 ... "ELB-HealthChecker/2.0"` が並んでいる場合**: `TRUST_HOSTS` をALBのDNS名だけに絞ったまま戻し忘れている。ALBのヘルスチェックはALBのDNS名ではなくターゲットのプライベートIPを`Host`ヘッダに使うため、DNS名限定のパターンだとヘルスチェック自体が400で弾かれる。`sudo sed -i 's/^TRUST_HOSTS=.*/TRUST_HOSTS=/' /var/www/app/.env && sudo systemctl reload apache2` で復旧する（詳細は[docs/trust-proxy.md](docs/trust-proxy.md)）。
  - **`/up`が`500`を返し、`storage/logs/laravel.log`に`preg_match(): No ending matching delimiter '}' found`が出ている場合**: `TRUST_HOSTS`の正規表現に`[0-9]{1,3}`のような中括弧`{}`を使う量指定子を書いている。Symfonyがパターン全体を`{...}i`で囲む実装のため中括弧が衝突しクラッシュする。`[0-9]+`のように書き換える（詳細は[docs/trust-proxy.md](docs/trust-proxy.md)）。
- **`curl` で 400 が返る**: `TRUST_HOSTS` が設定されている状態で、リクエストの `Host` ヘッダがそのパターンにマッチしていない（意図した挙動。[docs/trust-proxy.md](docs/trust-proxy.md) 参照）。ただし`/up`のヘルスチェック自体が400になっている場合は上記の「ターゲットグループがunhealthyのまま」を参照。
- **SSMで入れない**: EC2にアタッチされたインスタンスプロファイル（`AmazonSSMManagedInstanceCore`）と、SSM Agentが起動しているか（`systemctl status snap.amazon-ssm-agent.amazon-ssm-agent.service`）を確認する。22番ポートは意図的に開けていないためSSHでは入れない。
- **`terraform apply` が `iam:CreateRole` で `AccessDenied`**: 「1. 前提ツール」の「IAM権限について」を参照。`PowerUserAccess` だけでは不足する。

## 10. 最短ハンズオン手順（コマンドまとめ）

1〜9章を一通りやった後の振り返り用。上から順に実行すればインフラ構築から検証まで到達する。

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

---

## 付録: リポジトリ構成

```
.
├─ app/                # WhoAmIController, LogConnection
├─ bootstrap/app.php    # trustProxies / trustHosts / CSRF除外 / ミドルウェア登録
├─ routes/web.php       # GET/POST /whoami, /up はLaravel標準
├─ infra/                # Terraform一式
├─ scripts/              # bootstrap_state.sh, deploy.sh
└─ docs/                 # architecture.md, trust-proxy.md, deploy.md
```

## 付録: PHPバージョンを8.5に変更する場合

`.mise.toml` の `php = "8.4"` と、`infra/user_data.sh.tpl` 内の `PHP_VERSION=8.4`（ondrej/php PPAパッケージ名に使われる）の**両方**を変更すること。片方だけ変更するとローカルとEC2でPHPバージョンがずれる。
