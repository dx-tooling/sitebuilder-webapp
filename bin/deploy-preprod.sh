#!/usr/bin/env bash

set -e

# Deployment script for sitebuilder-webapp to preprod environment
# Usage: ./bin/deploy-preprod.sh [--skip-build] [--skip-migrations]

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
PROJECT_ROOT="$( cd "${SCRIPT_DIR}/.." >/dev/null 2>&1 && pwd )"
cd "${PROJECT_ROOT}"

# Configuration
SERVER="root@152.53.168.103"
REMOTE_DIR="/opt/sitebuilder-preprod"
COMPOSE_FILE="docker-compose.preprod.yml"
SKIP_BUILD=false
SKIP_MIGRATIONS=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --skip-build)
            SKIP_BUILD=true
            shift
            ;;
        --skip-migrations)
            SKIP_MIGRATIONS=true
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: $0 [--skip-build] [--skip-migrations]"
            exit 1
            ;;
    esac
done

echo "🚀 Deploying sitebuilder-webapp to preprod..."
echo "   Server: ${SERVER}"
echo "   Remote directory: ${REMOTE_DIR}"
echo ""

# Check if .env.preprod exists locally (for reference, but we'll use remote one)
if [ ! -f "${PROJECT_ROOT}/.env.preprod" ]; then
    echo "⚠️  Warning: .env.preprod not found locally. Make sure it exists on the server."
fi

# Step 1: Ensure remote directory exists and copy files to server
echo "📦 Preparing server directory..."
ssh "${SERVER}" "mkdir -p ${REMOTE_DIR}"

echo "📦 Copying files to server..."
rsync -avz \
    --exclude='.git' \
    --exclude='node_modules' \
    --exclude='var/cache' \
    --exclude='var/log' \
    --exclude='public/workspaces' \
    --exclude='.env.local' \
    "${PROJECT_ROOT}/" "${SERVER}:${REMOTE_DIR}/"

# Step 2: Build and start containers
echo ""
echo "🔨 Building and starting containers on server..."
ssh "${SERVER}" << EOF
    set -e
    cd ${REMOTE_DIR}
    export ETFS_PROJECT_NAME=sitebuilder_preprod
    
    # Verify .env.preprod exists
    if [ ! -f ".env.preprod" ]; then
        echo "❌ Error: .env.preprod file not found in ${REMOTE_DIR}!"
        echo "   Please create it before deploying."
        exit 1
    fi
    
    # Verify .env exists (should be transferred from repo)
    if [ ! -f ".env" ]; then
        echo "❌ Error: .env file not found in ${REMOTE_DIR}!"
        echo "   This file should be in the repository and transferred via rsync."
        exit 1
    fi
    
    # Verify outermost_router network exists
    if ! docker network inspect outermost_router > /dev/null 2>&1; then
        echo "❌ Error: outermost_router network does not exist!"
        echo "   Please start the outermost router first."
        exit 1
    fi
    
    # Build images (if not skipped)
    if [ "${SKIP_BUILD}" != "true" ]; then
        echo "Building Docker images..."
        docker compose -f ${COMPOSE_FILE} build
    fi
    
    # Start services (scale messenger to 3 instances)
    echo "Starting services..."
    docker compose -f ${COMPOSE_FILE} up -d --scale messenger=3
    
    # Restart nginx to pick up new container IPs (important after app container recreation)
    echo "Restarting nginx to pick up new container IPs..."
    docker compose -f ${COMPOSE_FILE} restart nginx
    
    # Wait for services to be ready
    echo "Waiting for services to start..."
    sleep 5
    
    # Check container status
    docker compose -f ${COMPOSE_FILE} ps
EOF

# Step 3: Create database and run migrations (if not skipped)
if [ "${SKIP_MIGRATIONS}" != "true" ]; then
    echo ""
    echo "🗄️  Setting up database..."
    ssh "${SERVER}" << EOF
        set -e
        cd ${REMOTE_DIR}
        export ETFS_PROJECT_NAME=sitebuilder_preprod
        
        # Wait for database and app container to be ready
        echo "Waiting for database and app container..."
        sleep 15
        
        # Verify app container is running
        echo "Checking app container status..."
        docker compose -f ${COMPOSE_FILE} ps app
        
        # Create database if it doesn't exist (won't fail if it already exists)
        echo "Creating database if needed..."
        docker compose -f ${COMPOSE_FILE} exec -T app php bin/console doctrine:database:create --if-not-exists --no-interaction
        
        # Show migration status before running
        echo "Current migration status:"
        docker compose -f ${COMPOSE_FILE} exec -T app php bin/console doctrine:migrations:status
        
        # Run migrations (do NOT swallow errors - let them propagate)
        echo "Running database migrations..."
        docker compose -f ${COMPOSE_FILE} exec -T app php bin/console doctrine:migrations:migrate --no-interaction
        
        echo "✓ Migrations completed successfully"
EOF
fi

# Step 4: Clear cache
echo ""
echo "🧹 Clearing and warming up cache..."
ssh "${SERVER}" << EOF
    set -e
    cd ${REMOTE_DIR}
    export ETFS_PROJECT_NAME=sitebuilder_preprod
    
    # Wait a bit more for app container to be fully ready
    echo "Waiting for app container to be ready..."
    sleep 5
    
    # Clear cache (with retry in case container isn't ready)
    if ! docker compose -f ${COMPOSE_FILE} exec -T app php bin/console cache:clear; then
        echo "⚠️  Cache clear failed, retrying in 5 seconds..."
        sleep 5
        docker compose -f ${COMPOSE_FILE} exec -T app php bin/console cache:clear
    fi
    
    # Warm up cache
    docker compose -f ${COMPOSE_FILE} exec -T app php bin/console cache:warmup || {
        echo "⚠️  Cache warmup failed, but continuing..."
    }
EOF

# Step 5: Verify deployment
echo ""
echo "✅ Verifying deployment..."
ssh "${SERVER}" << EOF
    set -e
    cd ${REMOTE_DIR}
    export ETFS_PROJECT_NAME=sitebuilder_preprod
    
    echo "Container status:"
    docker compose -f ${COMPOSE_FILE} ps
    
    echo ""
    echo "Checking if nginx is on outermost_router network:"
    docker network inspect outermost_router | grep -q sitebuilder_preprod_nginx && echo "✓ Nginx is on outermost_router network" || echo "✗ Nginx is NOT on outermost_router network"
    
    echo ""
    echo "Recent nginx logs:"
    docker compose -f ${COMPOSE_FILE} logs --tail=20 nginx
EOF

echo ""
echo "🎉 Deployment complete!"
echo ""
echo "Next steps:"
echo "  1. Verify the site: https://sitebuilder-preprod.dx-tooling.org"
echo "  2. Check logs if needed: ssh ${SERVER} 'cd ${REMOTE_DIR} && docker compose -f ${COMPOSE_FILE} logs -f'"
echo ""
