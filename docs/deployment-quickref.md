# Quick Reference: Preprod Deployment

## TL;DR

```bash
# From your local machine
cd sitebuilder-webapp
./bin/deploy-preprod.sh
```

## Manual Deployment

```bash
# 1. SSH to server
ssh root@152.53.168.103

# 2. Navigate to app directory
cd /opt/sitebuilder-preprod

# 3. Set environment variable
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

- **Preprod compose**: `docker compose.preprod.yml`
- **Preprod nginx config**: `docker/nginx/default.conf.preprod`
- **Deployment script**: `bin/deploy-preprod.sh`
- **Full guide**: `docs/deployment-preprod.md`

## Container Names

- `sitebuilder_preprod_app` - PHP-FPM application
- `sitebuilder_preprod_nginx` - Nginx web server (exposed to Traefik)
- `sitebuilder_preprod_messenger_1`, `sitebuilder_preprod_messenger_2`, `sitebuilder_preprod_messenger_3` - Symfony messenger workers (3 instances)
- `sitebuilder_preprod_mariadb` - MariaDB database

## Network Configuration

- **Internal network**: `sitebuilder_preprod_default` (app, messenger, mariadb)
- **External network**: `outermost_router` (nginx only, for Traefik routing)

## Traefik Labels

The nginx container has these labels for Traefik routing:
- `traefik.enable=true`
- `outermost_router.enable=true`
- `traefik.docker.network=outermost_router`
- `traefik.http.routers.sitebuilder-preprod.rule=Host(\`sitebuilder-preprod.dx-tooling.org\`)`
- `traefik.http.routers.sitebuilder-preprod.entrypoints=websecure`
- `traefik.http.routers.sitebuilder-preprod.tls=true`
- `traefik.http.services.sitebuilder-preprod.loadbalancer.server.port=80`

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

# Stop services
docker compose -f docker-compose.preprod.yml stop

# Start services
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
