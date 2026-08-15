# state は S3 に固定。bucket / key / region は `terraform init -backend-config=backend.hcl` で渡す。
# Terraform >= 1.11 の S3 ネイティブロック（use_lockfile）を使うため DynamoDB は不要。
terraform {
  backend "s3" {
    encrypt      = true
    use_lockfile = true
  }
}
