FROM php:8.2-apache

LABEL org.opencontainers.image.source='https://github.com/DECAF/ltx-microcloud'
LABEL org.opencontainers.image.url='https://decaf.de'
LABEL org.opencontainers.image.vendor='DECAF'

RUN apt-get update --fix-missing && \
    apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    mariadb-client \
    telnet \
    vim \
    zip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_mysql
RUN docker-php-ext-configure gd --enable-gd --with-freetype --with-jpeg --with-webp
RUN docker-php-ext-install -j$(nproc) gd

RUN usermod --shell /bin/bash www-data
RUN mkdir -p /var/www/html
RUN chown -R www-data.www-data /var/www
RUN chmod -R 775 /var/www

USER www-data

COPY --chown=www-data:www-data . /var/www/html

ENV HISTCONTROL="ignoredups"