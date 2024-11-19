FROM php:apache

LABEL org.opencontainers.image.source='https://github.com/DECAF/wol-hmp'
LABEL org.opencontainers.image.url='https://decaf.de'
LABEL org.opencontainers.image.vendor='DECAF'

COPY --chown=www-data:www-data . /var/www/html
