output "alb_dns_name" {
  description = "ALB の DNS 名"
  value       = aws_lb.main.dns_name
}

output "alb_url" {
  description = "ALB へのHTTPS URL（自己署名のため curl -k で叩く）"
  value       = "https://${aws_lb.main.dns_name}"
}

output "instance_id" {
  description = "SSM Session Manager で接続する対象のインスタンスID"
  value       = aws_instance.app.id
}

output "vpc_id" {
  description = "作成したVPCのID"
  value       = aws_vpc.main.id
}
