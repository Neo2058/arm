FROM php:8.2-fpm

# Устанавливаем системные зависимости + библиотеки для интл и картинок
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    libxml2-dev \
    libicu-dev \
    libpng-dev \
    libjpeg-dev \
    zip \
    unzip \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Устанавливаем расширения PHP
# intl — для локализации, gd — для работы с изображениями
RUN docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql zip xml intl gd

# Redis
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
