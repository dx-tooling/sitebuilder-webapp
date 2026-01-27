FROM php:8.4-fpm

ARG TARGETPLATFORM

RUN apt-get update -y && apt-get install -y \
    git \
    curl \
    wget \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    gpg \
    mariadb-client

RUN install -dm 755 /etc/apt/keyrings
RUN wget -qO - https://mise.jdx.dev/gpg-key.pub | gpg --dearmor | tee /etc/apt/keyrings/mise-archive-keyring.gpg 1> /dev/null
RUN if [ "$TARGETPLATFORM" = "linux/amd64" ]; then ARCHITECTURE=amd64; elif [ "$TARGETPLATFORM" = "linux/arm/v7" ]; then ARCHITECTURE=arm; elif [ "$TARGETPLATFORM" = "linux/arm64" ]; then ARCHITECTURE=arm64; else ARCHITECTURE=amd64; fi \
    && echo "deb [signed-by=/etc/apt/keyrings/mise-archive-keyring.gpg arch=${ARCHITECTURE}] https://mise.jdx.dev/deb stable main" | tee /etc/apt/sources.list.d/mise.list
RUN apt-get update -y
RUN apt-get install -y mise

# Install Docker CLI for workspace isolation (running commands in sibling containers)
# Using official Docker repo for the CLI
RUN install -m 0755 -d /etc/apt/keyrings && \
    curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc && \
    chmod a+r /etc/apt/keyrings/docker.asc && \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null && \
    apt-get update -y && \
    apt-get install -y docker-ce-cli

# Install Cursor CLI for agent execution
RUN curl -fsS https://cursor.com/install | bash

# Clean up to reduce image size
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
# Each one serves specific purpose:
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    intl \
    zip \
    opcache

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY ./docker/app/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY ./docker/app/www.conf /usr/local/etc/php-fpm.d/zzz-www.conf

# Set working directory
WORKDIR /var/www

# What command to run
CMD ["php-fpm"]

# Document that we use port 9000
EXPOSE 9000

# Mise configuration - use /opt/mise for shared volume mount at runtime
# These directories will be mounted as a Docker volume shared between containers
ENV MISE_DATA_DIR=/opt/mise/data
ENV MISE_CACHE_DIR=/opt/mise/cache
ENV MISE_STATE_DIR=/opt/mise/state

# Create mise directories and pre-install Node
# This ensures Node is available without runtime installation (which can fail with lock issues)
RUN mkdir -p /opt/mise/data /opt/mise/cache /opt/mise/state && \
    chmod -R 777 /opt/mise && \
    mise install node@24

# Shell activation for interactive use (debugging)
RUN echo 'eval "$(/usr/bin/env mise activate bash)"' >> ~/.bashrc
RUN echo 'eval "$(/usr/bin/env mise activate zsh)"' >> ~/.zshrc

# Create container home directory structure for runtime cache/config directories
# This prevents polluting the mounted /var/www directory
# Note: mise directories are now in /opt/mise (shared volume), not here
RUN mkdir -p /tmp/container-home/.cache \
    /tmp/container-home/.config \
    /tmp/container-home/.local/share \
    /tmp/container-home/.gnupg \
    /tmp/container-home/.npm && \
    chmod -R 777 /tmp/container-home

# Configure Git to trust all directories (needed for workspace git operations)
# Workspaces may be created by different users/processes, causing "dubious ownership" errors
# Using --system to write to /etc/gitconfig which persists regardless of HOME setting
RUN git config --system --add safe.directory '*'
