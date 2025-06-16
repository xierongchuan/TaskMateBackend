FROM php:8.4.8-fpm

# Установка системных зависимостей
RUN apt-get update -y && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    zip \
    libonig-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libmemcached-tools

# Установка PHP-расширений
RUN docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# Активация установки Redis через PECL
RUN pecl install redis-6.2.0 && docker-php-ext-enable redis

# Очистка кэша apt
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY ./composer.json ./composer.lock ./
RUN composer install --optimize-autoloader --no-dev --no-scripts

COPY ./ .

EXPOSE 8000

CMD ["php-fpm"]
