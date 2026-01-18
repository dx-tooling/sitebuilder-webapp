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
RUN echo 'eval "$(/usr/bin/env mise activate bash)"' >> ~/.bashrc
RUN cd /var/www && mise trust

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

# Set working directory
WORKDIR /var/www

# What command to run
CMD ["php-fpm"]

# Document that we use port 9000
EXPOSE 9000

RUN mkdir -p ~/.local/state/mise/trusted-configs
RUN ln -s /var/www ~/.local/state/mise/trusted-configs/
RUN echo 'eval "$(/usr/bin/env mise activate zsh)"' >> ~/.zshrc
