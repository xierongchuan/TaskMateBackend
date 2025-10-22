# ---------------- STAGE: build ----------------
FROM php:8.4.13-fpm AS build

# 1) Системные библиотеки для сборки расширений
RUN apt-get update -y \
  && apt-get install -y --no-install-recommends \
  git unzip zip \
  libzip-dev libonig-dev libxml2-dev \
  libpng-dev libjpeg-dev libfreetype6-dev \
  libmemcached-tools \
  libpq-dev postgresql-client \
  curl \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/src_telegram_bot_api

# 2) PHP-расширения: PostgreSQL, GD и т.д.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) \
  mbstring exif pcntl bcmath zip \
  pdo pdo_pgsql gd \
  && docker-php-ext-install pgsql

# 3) Redis через PECL
RUN set -eux; \
  pecl install redis-6.2.0 || true; \
  docker-php-ext-enable redis || true

# 4) Composer (официальный образ)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 5) Копирование composer-файлов и установка зависимостей
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --no-scripts || true

# 6) Копирование кодовой базы
COPY . .

# 7) Права на каталоги
RUN chown -R www-data:www-data storage storage/framework bootstrap/cache \
  && chmod -R 755 storage storage/framework bootstrap/cache || true

# Например: php artisan config:cache && php artisan route:cache

# ---------------- STAGE: runner ----------------
FROM php:8.4.13-fpm AS runner

WORKDIR /var/www/src_telegram_bot_api

# Устанавливаем минимальные runtime-зависимости
RUN apt-get update -y \
  && apt-get install -y --no-install-recommends \
  libpng-dev libjpeg-dev libfreetype6-dev \
  libzip-dev libonig-dev libxml2-dev \
  sqlite3 libsqlite3-dev \
  libpq-dev \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

# Включаем необходимые расширения в runtime
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) \
  mbstring exif pcntl bcmath zip \
  pdo pdo_pgsql gd \
  && docker-php-ext-install pgsql

# Устанавливаем redis-расширение в runtime (если нужно)
RUN set -eux; \
  pecl install redis-6.2.0 || true; \
  docker-php-ext-enable redis || true

# Копируем артефакты и код из build
COPY --from=build /var/www/src_telegram_bot_api /var/www/src_telegram_bot_api
COPY --from=build /usr/bin/composer /usr/bin/composer

# Права
RUN chown -R www-data:www-data storage storage/framework bootstrap/cache \
  && chmod -R 755 storage storage/framework bootstrap/cache || true

# Документирование порта
EXPOSE 9000

CMD ["php-fpm", "-F"]
