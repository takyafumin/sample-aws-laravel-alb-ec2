# デプロイ

## 初期プロビジョニング（user_data）

EC2起動時に [`infra/user_data.sh.tpl`](../infra/user_data.sh.tpl) が一度実行される（起動のたびに再実行はされないが、スクリプト自体は再実行しても安全な冪等設計）。内容は以下の通り:

1. SSM Agentが有効か確認し、無ければ `snap install amazon-ssm-agent` して有効化。
2. `apt-get update` → `software-properties-common` → ondrej/php PPA追加。
3. `apache2` / `php8.4` 一式（`sqlite3, mbstring, xml, curl, bcmath, intl, zip`）/ `git` / `composer` をインストール。
4. `git_repo_url`（`terraform apply -var git_repo_url=...` で指定したリポジトリ）を `/var/www/app` にclone（既にあれば `fetch` + `reset --hard`）。
5. `composer install --no-dev --optimize-autoloader`。
6. `.env` を `.env.example` から作成し、`DB_CONNECTION=sqlite` / `DB_DATABASE` を絶対パスに設定、SQLiteファイルを `touch`、`APP_KEY` が未設定なら `key:generate`。
7. `php artisan migrate --force`。
8. **`config:cache` は実行しない**（理由は [trust-proxy.md](trust-proxy.md) 参照）。
9. Apache vhost（`DocumentRoot=/var/www/app/public`、`AllowOverride All`）を設定し、デフォルトvhostを無効化、`www-data` に所有権変更、`storage`/`bootstrap/cache` を書き込み可にして起動。

> ALBのターゲットグループヘルスチェックは `/up`。Apacheが起動し `/up` が200を返すまでALBは対象インスタンスを `unhealthy` と表示する。プロビジョニング完了まで数分かかる。

## 更新デプロイ（SSM経由）

コード更新は [`scripts/deploy.sh`](../scripts/deploy.sh) をEC2上で実行して行う（`git pull` ベース。Deployer等の外部ツールは使わない）。

### 対話的に実行する場合
```bash
aws ssm start-session --target <instance_id>
sudo bash /var/www/app/scripts/deploy.sh
```

### 非対話（CLIから1コマンドで実行する場合）
```bash
aws ssm send-command \
  --document-name "AWS-RunShellScript" \
  --targets "Key=instanceids,Values=<instance_id>" \
  --parameters 'commands=["sudo bash /var/www/app/scripts/deploy.sh"]'
```

`<instance_id>` は `terraform output -raw instance_id` で取得できる。

## privateリポジトリを使う場合（deploy key）

`user_data.sh.tpl` は公開リポジトリを前提に `git clone <url>` を直接実行する。privateリポジトリを使う場合は、読み取り専用のdeploy keyをEC2に配置し、SSH URL経由でcloneする構成に変更する。

1. GitHub側でリポジトリの Settings → Deploy keys から読み取り専用の鍵を発行する。
2. 秘密鍵をAWS Systems Manager Parameter Store（`SecureString`）等に保存する（**リポジトリやuser_dataに秘密鍵を直書きしない**）。
3. `user_data.sh.tpl` 側で、起動時に Parameter Store から鍵を取得して `~/.ssh/id_ed25519` に配置し、`git clone git@github.com:<org>/<repo>.git` のようにSSH URLでcloneするよう変更する。
4. `~/.ssh/known_hosts` に `github.com` のホスト鍵を事前登録する（`ssh-keyscan github.com` の結果を埋め込むか、`StrictHostKeyChecking=accept-new` を使う）。
5. IAMロールにParameter Store読み取り権限（`ssm:GetParameter` 等）を追加する。

この手順は本リポジトリの既定構成には含めていない（既定は公開リポジトリ前提）。
