FROM php:8.4.10-fpm

# 1) Системные библиотеки для сборки расширений
RUN apt-get update -y

RUN apt-get install -y \
      git unzip zip \
      libzip-dev libonig-dev libxml2-dev \
      libpng-dev libjpeg-dev libfreetype6-dev \
      libmemcached-tools \
      libpq-dev postgresql-client

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 2) PHP-расширения: PostgreSQL, GD и т.д.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
      mbstring exif pcntl bcmath zip \
      pdo_pgsql gd \
  && docker-php-ext-install pgsql

# 3) Redis через PECL
RUN pecl install redis-6.2.0 \
 && docker-php-ext-enable redis

# 4) Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 5) Установка зависимостей приложения
COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader --no-dev --no-scripts

COPY . .

EXPOSE 8000
CMD ["php-fpm"]
