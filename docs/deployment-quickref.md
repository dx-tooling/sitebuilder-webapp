# Quick Reference: Preprod Deployment

Use this as a generic reference. Replace placeholders with your server and host. If your org uses a hosting repo (e.g. `sitebuilder-webapp-hosting-joboo`), run its integrate script first; then the deploy task and full guide will be available.

## TL;DR

```bash
# From your local machine (after integrating hosting repo if applicable)
cd sitebuilder-webapp
mise run deploy:preprod
# Or: ./.mise/tasks/deploy/preprod.sh
```

## Manual Deployment (placeholders)

```bash
# 1. SSH to server
ssh root@<PREPROD_SERVER>

# 2. Navigate to app directory
cd <PREPROD_REMOTE_DIR>

# 3. Set environment variable (example name)
export ETFS_PROJECT_NAME=sitebuilder_preprod

# 4. Build and start (messenger scaled to 3 instances)
docker compose -f docker-compose.preprod.yml build
docker compose -f docker-compose.preprod.yml up -d --scale messenger=3

# 5. Create database and run migrations
docker compose -f docker-compose.preprod.yml exec app php bin/console doctrine:database:create --if-not-exists --no-interaction
docker compose -f docker-compose.preprod.yml exec app php bin/console doctrine:migrations:migrate --no-interaction

# 6. Clear cache
docker compose -f docker-compose.preprod.yml exec app php bin/console cache:clear
```

## Key Files

- **Preprod compose**: `docker-compose.preprod.yml` (from hosting repo when integrated)
- **Preprod nginx config**: `docker/nginx/default.conf.preprod` (generic, in this repo)
- **Deployment script**: `.mise/tasks/deploy/preprod.sh` (from hosting repo when integrated)
- **Full guide**: `docs/deployment-preprod.md` (stub); company-specific full guide from hosting repo (e.g. `docs/deployment-preprod-joboo.md`)

## Environment Variables

Create `.env.preprod` on the server with:
- `APP_ENV=preprod`
- `APP_SECRET=<random-secret>`
- `APP_DEBUG=0`
- Database credentials (DATABASE_HOST, DATABASE_USER, DATABASE_PASSWORD, etc.)

## Common Commands

```bash
# View logs
docker compose -f docker-compose.preprod.yml logs -f

# Restart services
docker compose -f docker-compose.preprod.yml restart

# Stop / start services
docker compose -f docker-compose.preprod.yml stop
docker compose -f docker-compose.preprod.yml start

# Rebuild after code changes
docker compose -f docker-compose.preprod.yml build
docker compose -f docker-compose.preprod.yml up -d --scale messenger=3
```

## Troubleshooting

```bash
# Check if nginx is on outermost_router network
docker network inspect outermost_router | grep sitebuilder

# Check Traefik can see the container
docker ps | grep sitebuilder_preprod_nginx

# Test from inside nginx container
docker compose -f docker-compose.preprod.yml exec nginx curl http://app:9000

# Check database connection
docker compose -f docker-compose.preprod.yml exec app php bin/console doctrine:database:create --if-not-exists

# Check MariaDB volume (data persistence)
docker volume inspect sitebuilder_preprod_mariadb_data
```
