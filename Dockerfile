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

# Increase limits for file uploads (Livewire + Filament in admin)
RUN { \
        echo 'upload_max_filesize = 100M'; \
        echo 'post_max_size = 100M'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 300'; \
        echo 'max_input_time = 300'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# Timezone (removes the "Invalid date.timezone value ''" warning)
RUN echo 'date.timezone = UTC' > /usr/local/etc/php/conf.d/timezone.ini

# Entrypoint for storage preparation (permissions + livewire-tmp dir)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
