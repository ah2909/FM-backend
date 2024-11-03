FROM php:8.2-apache

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    nano

RUN docker-php-ext-install pdo_mysql mysqli mbstring exif pcntl bcmath gd zip
RUN pecl install xdebug && docker-php-ext-enable xdebug
    
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY . .

RUN chown -R www-data:www-data /var/www/html

ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

EXPOSE 8000