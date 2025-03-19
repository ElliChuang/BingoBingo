FROM php:8.2-fpm

WORKDIR /var/www

# 安裝 PHP 擴展
RUN apt-get update && apt-get install -y \
    curl \
    libzip-dev \
    libpng-dev \
    libgssapi-krb5-2 \
    ca-certificates \
    locales \
    apt-transport-https \
    gnupg2 \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install bcmath mysqli pdo pdo_mysql zip gd

# 設定權限
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www

CMD ["php-fpm"]