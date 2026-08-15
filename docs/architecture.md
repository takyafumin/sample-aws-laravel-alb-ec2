# アーキテクチャ

## 構成図

```mermaid
flowchart LR
    client["client<br/>(curl -k)"] -->|HTTPS 443<br/>自己署名証明書| alb["ALB<br/>TLS終端"]
    alb -->|HTTP 80<br/>X-Forwarded-* 付与| ec2["EC2 (Ubuntu 24.04)<br/>Apache + mod_php 8.4<br/>Laravel 13"]
    ec2 --> sqlite[("SQLite<br/>database/database.sqlite")]
```

- クライアントは自己署名証明書のALBに対して `curl -k` でHTTPSアクセスする。
- ALBはTLSを終端し、EC2へは平文HTTP:80で転送する。このときALBが `X-Forwarded-Proto: https` などのヘッダを付与する。
- EC2上のApache + mod_phpがLaravelを実行し、SQLite（ローカルファイル）に読み書きする。

## なぜ自己署名HTTPSなのか

このリポジトリの目的は TrustProxies / TrustHosts の挙動差分を **scheme（http/https）の反転として観測できること** にある。ALBでHTTPS終端しEC2へHTTP転送する構成では、Laravel側が `X-Forwarded-Proto` を信頼するかどうかで `isSecure()` / `getScheme()` の結果が変わる。これを再現するには実際にALBをHTTPSで受ける必要があるが、独自ドメイン・公開証明書は非ゴール（[GENERATION_SPEC.md](../GENERATION_SPEC.md) 1章）としたため、`tls_self_signed_cert` で生成した証明書をACMにimportして使う。アクセスは常に `curl -k`（証明書検証をスキップ）で行う。

> **セキュリティ初心者向け補足**: `curl -k`（`--insecure`）は「証明書が本物か確認しない」オプション。通常のWebサイトへのアクセスでこれを使うのは危険で、通信経路上の第三者が別のサーバーになりすます「中間者攻撃（MITM）」を検知できなくなる。この検証環境で問題ないのは、①自分でALBを立てて自分でアクセスしているだけで経路上に信頼できない第三者がいない、②やり取りする内容もダミーの診断情報だけで秘匿すべき情報がない、という閉じた実験環境だから。実際のプロダクションで社外向けにサービスを公開する場合は、必ずAWS Certificate Manager (ACM) の無料の公開証明書か、Let's Encrypt等の正規の証明書を使うこと。

## コスト概算（東京リージョン, 目安）

| リソース | 概算月額 |
|---|---|
| ALB（稼働時間 + LCU） | 約 $16〜25 |
| EC2 t3.micro（オンデマンド） | 約 $8〜9 |
| データ転送・EBS等 | 数ドル程度 |

検証が終わったら `terraform destroy` で速やかに削除することを推奨する（README「8. 後片付け」参照）。

## パブリックサブネット採用理由（NAT Gateway回避）

EC2をプライベートサブネットに置く構成も検討したが、その場合はSSMやアウトバウンド通信（apt/composer/git）のために NAT Gateway かVPCエンドポイントが必要になり、コストと構成が増える。本検証環境は非ゴールとして「高可用・本番運用」を明示的に除外しているため、EC2はALBと同じくパブリックサブネットに置き、SG側で80番をALBからのみに絞ることでアクセス制御する（22番は開けない・SSMのみ）。

## なぜSSHを閉じてSSM Session Managerを使うのか

EC2のセキュリティグループは22番（SSH）を一切開けていない。これは単なる好みではなく、以下のような理由がある。

- **攻撃対象領域（アタックサーフェス）を減らす**: インターネットに22番ポートを晒すと、常時ボットによる総当たりログイン試行の対象になる。ポート自体を閉じてしまえば、SSHの脆弱性やパスワード漏洩によるリスクをまるごと排除できる。
- **鍵の管理・失効が不要になる**: SSH鍵を使う場合、誰にどの鍵を配るか、退職者の鍵をどう失効するかという運用が発生する。SSM Session ManagerはIAM権限（＝すでに管理している仕組み）でアクセス制御でき、鍵の配布・失効という別の管理コストが発生しない。
- **操作が監査ログに残る**: SSM経由の接続・実行コマンドはCloudTrail/SSMのログに記録される。「誰が・いつ・何をしたか」を後から追跡できる（SSHの素の接続だとこれが難しい）。

まとめると、「そもそも入り口を作らない」＋「入るときは必ず記録が残る仕組みを使う」という、最小権限・多層防御の考え方の実践例になっている。

## 付録：プライベートサブネット化する場合のVPCエンドポイント

EC2をプライベートサブネットに移す場合、SSM Session Managerを維持するには以下3種のインターフェース型VPCエンドポイントが必要になる（NAT Gatewayでも代替可能だがコストが高い）。

- `com.amazonaws.<region>.ssm`
- `com.amazonaws.<region>.ssmmessages`
- `com.amazonaws.<region>.ec2messages`

加えて、composer/git等のアウトバウンド通信用にNAT Gatewayが別途必要になる点に注意。
