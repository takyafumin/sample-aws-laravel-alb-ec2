variable "region" {
  description = "AWS リージョン"
  type        = string
  default     = "ap-northeast-1"
}

variable "project_name" {
  description = "タグ付け用のプロジェクト名"
  type        = string
  default     = "trust-verify"
}

variable "instance_type" {
  description = "EC2 インスタンスタイプ"
  type        = string
  default     = "t3.micro"
}

variable "git_repo_url" {
  description = "EC2 起動時に user_data で clone する Git リポジトリの URL"
  type        = string
}

variable "git_branch" {
  description = "clone するブランチ"
  type        = string
  default     = "main"
}

variable "vpc_cidr" {
  description = "VPC の CIDR"
  type        = string
  default     = "10.0.0.0/16"
}

variable "public_subnet_cidrs" {
  description = "パブリックサブネットの CIDR（2AZ分）"
  type        = list(string)
  default     = ["10.0.1.0/24", "10.0.2.0/24"]
}
