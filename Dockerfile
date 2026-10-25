FROM php:8.2.35-fpm

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
    python3 \
    python3-pip \
    clamdscan \
    && rm -rf /var/lib/apt/lists/* \
    && pip3 install --break-system-packages --no-cache-dir pdfplumber

# Устанавливаем расширения PHP
# intl — для локализации, gd — для работы с изображениями
RUN docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql zip xml intl gd

# Redis: GitHub tarball — pecl.php.net недоступен из build-контейнера
RUN curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/6.3.0.tar.gz -o /tmp/redis.tar.gz \
    && mkdir -p /usr/src/php/ext/redis \
    && tar -xzf /tmp/redis.tar.gz -C /usr/src/php/ext/redis --strip-components=1 \
    && rm /tmp/redis.tar.gz \
    && docker-php-ext-install redis

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

# PHP2Shell: disable session upload progress before request parse (CVE-2026-109618/109619)
COPY docker/php/zz-php2shell.ini /usr/local/etc/php/conf.d/zz-php2shell.ini

# Chat photos + ClamAV on the same FPM pool; image default pm.max_children=5 is too small.
COPY docker/php-fpm/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

# Entrypoint for storage preparation (permissions + livewire-tmp dir)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
