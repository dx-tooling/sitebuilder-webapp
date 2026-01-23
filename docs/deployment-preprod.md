# Deployment Guide: sitebuilder-webapp to preprod

This guide covers deploying `sitebuilder-webapp` to `sitebuilder-preprod.dx-tooling.org` on the Ubuntu server at `152.53.168.103`.

## Prerequisites

- SSH access to `root@152.53.168.103`
- The outermost Traefik router must be running (see `infrastructure/rootserver-hosting/README.md`)
- **DNS**: A wildcard A record (`*.dx-tooling.org`) already points to `152.53.168.103`, so no DNS setup is needed for `sitebuilder-preprod.dx-tooling.org`
- TLS certificate for `*.dx-tooling.org` must be configured in Traefik (already done per infrastructure setup)

## Architecture

The deployment uses:
- **Outermost Traefik**: Terminates TLS and routes HTTPS traffic to the nginx container
- **Nginx container**: Serves the Symfony application, connected to `outermost_router` network
- **PHP-FPM container**: Runs the Symfony application
- **Messenger containers**: 3 instances processing async messages in parallel
- **MariaDB container**: Database for the application with **persistent data storage** via Docker named volume

All containers except nginx are on an internal network. Only nginx is exposed to Traefik.

**Data Persistence**: MariaDB data is stored in a named Docker volume (`sitebuilder_preprod_mariadb_data`), ensuring data survives container restarts, removals, and server reboots. See the "Data Persistence" section below for details.

## Deployment Steps

### 1. Connect to the Server

```bash
ssh root@152.53.168.103
```

### 2. Create Application Directory

```bash
mkdir -p /opt/sitebuilder-preprod
cd /opt/sitebuilder-preprod
```

### 3. Clone or Copy the Repository

If deploying from git:

```bash
git clone <repository-url> .
# Or if you're deploying from your local machine:
# scp -r /path/to/sitebuilder-webapp/* root@152.53.168.103:/opt/sitebuilder-preprod/
```

### 4. Set Up Environment Variables

Create a `.env.preprod` file with preprod configuration:

```bash
cd /opt/sitebuilder-preprod
cat > .env.preprod << 'EOF'
# Application
APP_ENV=preprod
APP_SECRET=<generate-a-random-secret-here>
APP_DEBUG=0

# Database
DATABASE_PRODUCT=mysql
DATABASE_HOST=mariadb
DATABASE_PORT=3306
DATABASE_DB=sitebuilder_preprod
DATABASE_USER=sitebuilder
DATABASE_PASSWORD=<set-secure-password>
DATABASE_SERVERVERSION=mariadb-12.0.2

# Mailer (configure as needed)
MAILER_DSN=null://null

# Workspace root (optional, defaults to /var/www/public/workspaces)
# WORKSPACE_ROOT=/var/www/public/workspaces
EOF
```

Generate a secure APP_SECRET:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Set secure database passwords:

```bash
# Generate random password
openssl rand -base64 32
```

### 5. Create Preprod Nginx Config

The preprod nginx config is already created at `docker/nginx/default.conf.preprod`. It sets `APP_ENV=preprod` for PHP-FPM.

### 6. Verify Outermost Router Network Exists

```bash
docker network inspect outermost_router
```

If it doesn't exist, you need to start the outermost router first (see `infrastructure/rootserver-hosting/outermost-router/launch_outermost_router.sh`).

### 7. Build and Start Containers

```bash
cd /opt/sitebuilder-preprod

# Set project name environment variable (used in docker compose)
export ETFS_PROJECT_NAME=sitebuilder_preprod

# Build images
docker compose -f docker-compose.preprod.yml build

# Start services (messenger is scaled to 3 instances)
docker compose -f docker-compose.preprod.yml up -d --scale messenger=3
```

### 8. Create Database and Run Migrations

```bash
# Wait for database to be ready
sleep 10

# Create database if it doesn't exist (won't fail if it already exists)
docker compose -f docker-compose.preprod.yml exec app php bin/console doctrine:database:create --if-not-exists --no-interaction

# Run migrations
docker compose -f docker-compose.preprod.yml exec app php bin/console doctrine:migrations:migrate --no-interaction
```

### 9. Clear and Warm Up Cache

```bash
docker compose -f docker-compose.preprod.yml exec app php bin/console cache:clear
docker compose -f docker-compose.preprod.yml exec app php bin/console cache:warmup
```

### 10. Verify Deployment

Check container status:

```bash
docker compose -f docker-compose.preprod.yml ps
```

Check logs:

```bash
docker compose -f docker-compose.preprod.yml logs -f nginx
docker compose -f docker-compose.preprod.yml logs -f app
```

Verify Traefik routing:

```bash
# Check if Traefik sees the container
docker ps | grep sitebuilder_preprod_nginx

# Test the site
curl -I https://sitebuilder-preprod.dx-tooling.org
```

### 11. Set Up Asset Compilation (if needed)

If you need to compile frontend assets:

```bash
docker compose -f docker-compose.preprod.yml exec app npm install
docker compose -f docker-compose.preprod.yml exec app npm run build
```

## Updating the Deployment

### Pull Latest Code

```bash
cd /opt/sitebuilder-preprod
git pull  # if using git
# Or copy new files via scp
```

### Rebuild and Restart

```bash
export ETFS_PROJECT_NAME=sitebuilder_preprod
docker compose -f docker-compose.preprod.yml build
docker compose -f docker-compose.preprod.yml up -d --scale messenger=3
docker compose -f docker-compose.preprod.yml exec app php bin/console doctrine:database:create --if-not-exists --no-interaction
docker compose -f docker-compose.preprod.yml exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f docker-compose.preprod.yml exec app php bin/console cache:clear
```

## Troubleshooting

### Check Container Logs

```bash
docker compose -f docker-compose.preprod.yml logs app
docker compose -f docker-compose.preprod.yml logs nginx
docker compose -f docker-compose.preprod.yml logs messenger
# Or view logs for a specific messenger instance:
# docker logs sitebuilder_preprod_messenger_1
```

### Verify Network Connectivity

```bash
# Check if nginx is on outermost_router network
docker network inspect outermost_router | grep sitebuilder

# Check if Traefik can see the container
docker exec <traefik-container-name> wget -qO- http://localhost:8080/api/http/routers | jq
```

### Check Traefik Labels

```bash
docker inspect sitebuilder_preprod_nginx | jq '.[0].Config.Labels'
```

### Database Connection Issues

```bash
# Test database connection from app container
docker compose -f docker-compose.preprod.yml exec app php bin/console doctrine:database:create --if-not-exists
```

### Permission Issues

If you encounter permission issues with workspace directories:

```bash
docker compose -f docker-compose.preprod.yml exec app chown -R www-data:www-data /var/www/public/workspaces
```

## Security Considerations

1. **Database Passwords**: Use strong, randomly generated passwords stored in `.env.preprod`
2. **APP_SECRET**: Must be unique and secret
3. **File Permissions**: Ensure workspace directories have appropriate permissions
4. **Environment Variables**: Never commit `.env.preprod` to version control
5. **TLS**: Already handled by Traefik at the edge

## Maintenance

### View Running Containers

```bash
docker compose -f docker-compose.preprod.yml ps
```

### Stop Services

```bash
docker compose -f docker-compose.preprod.yml stop
```

### Start Services

```bash
docker compose -f docker-compose.preprod.yml start
```

### Remove Everything (⚠️ Destroys Data)

```bash
docker compose -f docker-compose.preprod.yml down -v
```

**Warning**: The `-v` flag removes volumes, which will delete all database data. Use with caution!

## Data Persistence

The MariaDB data is stored in a **named Docker volume** (`sitebuilder_preprod_mariadb_data`), which ensures data persistence across:

- ✅ Container restarts
- ✅ Container removals (`docker compose down` without `-v`)
- ✅ Container updates/recreates
- ✅ Server reboots

The data is stored at Docker's volume location (typically `/var/lib/docker/volumes/sitebuilder_preprod_mariadb_data`).

**To preserve data**: Always use `docker compose down` (without `-v`) when stopping services.

**To remove data**: Use `docker compose down -v` or `docker volume rm sitebuilder_preprod_mariadb_data`.

## Backup Database

```bash
# Backup using root user (default credentials)
docker compose -f docker-compose.preprod.yml exec mariadb mysqldump -uroot -psecret app_preprod > backup-$(date +%Y%m%d).sql

# Or backup all databases
docker compose -f docker-compose.preprod.yml exec mariadb mysqldump -uroot -psecret --all-databases > backup-all-$(date +%Y%m%d).sql
```

## Restore Database

```bash
# Restore a specific database
docker compose -f docker-compose.preprod.yml exec -T mariadb mysql -uroot -psecret app_preprod < backup-YYYYMMDD.sql

# Or restore all databases
docker compose -f docker-compose.preprod.yml exec -T mariadb mysql -uroot -psecret < backup-all-YYYYMMDD.sql
```

## Volume Management

```bash
# List volumes
docker volume ls | grep sitebuilder_preprod

# Inspect volume details
docker volume inspect sitebuilder_preprod_mariadb_data

# Backup volume directly (alternative to mysqldump)
docker run --rm -v sitebuilder_preprod_mariadb_data:/data -v $(pwd):/backup ubuntu tar czf /backup/mariadb-volume-backup-$(date +%Y%m%d).tar.gz /data

# Restore volume directly
docker run --rm -v sitebuilder_preprod_mariadb_data:/data -v $(pwd):/backup ubuntu tar xzf /backup/mariadb-volume-backup-YYYYMMDD.tar.gz -C /
```
