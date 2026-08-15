# 独自ドメイン/公開ACM証明書は非ゴール。自己署名証明書をACMにimportしてHTTPS終端する。
# アクセス時は `curl -k` を使う前提。
resource "tls_private_key" "self_signed" {
  algorithm = "RSA"
  rsa_bits  = 2048
}

resource "tls_self_signed_cert" "self_signed" {
  private_key_pem = tls_private_key.self_signed.private_key_pem

  subject {
    common_name  = "trust-verify.local"
    organization = var.project_name
  }

  validity_period_hours = 8760 # 1年

  allowed_uses = [
    "key_encipherment",
    "digital_signature",
    "server_auth",
  ]
}

resource "aws_acm_certificate" "self_signed" {
  private_key      = tls_private_key.self_signed.private_key_pem
  certificate_body = tls_self_signed_cert.self_signed.cert_pem

  lifecycle {
    create_before_destroy = true
  }

  tags = {
    Name = "${var.project_name}-self-signed-cert"
  }
}
