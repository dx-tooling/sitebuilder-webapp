#!/bin/sh
set -e

# Generate htpasswd file from environment variables
if [ -n "$BASIC_AUTH_USER" ] && [ -n "$BASIC_AUTH_PASSWORD" ]; then
    echo "Generating htpasswd file..."
    # Use openssl to generate APR1 (MD5) hash compatible with nginx
    HASH=$(openssl passwd -apr1 "$BASIC_AUTH_PASSWORD")
    echo "$BASIC_AUTH_USER:$HASH" > /etc/nginx/.htpasswd
    chmod 644 /etc/nginx/.htpasswd
    echo "Basic auth configured for user: $BASIC_AUTH_USER"
else
    echo "WARNING: BASIC_AUTH_USER or BASIC_AUTH_PASSWORD not set, basic auth disabled"
    # Create empty htpasswd to prevent nginx startup errors
    touch /etc/nginx/.htpasswd
fi

# Execute the original nginx entrypoint
exec /docker-entrypoint.sh "$@"
