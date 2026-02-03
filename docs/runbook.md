# Runbook

How do I solve tasks and problems during production runtime?

## Access preprod MariaDB

```bash
ssh root@152.53.168.103
cd /opt/sitebuilder-preprod
docker compose -f docker-compose.preprod.yml exec app mysql -h mariadb -uroot -psecret app_preprod
```
