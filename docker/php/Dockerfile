FROM php:8.5-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    linux-headers \
    $PHPIZE_DEPS

# Note: pdo and opcache are already built into PHP 8.5
RUN docker-php-ext-install pdo_pgsql pgsql zip pcntl bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
