FROM php:8.3-cli-alpine

RUN apk add --no-cache curl-dev \
    && docker-php-ext-install pdo_mysql curl

WORKDIR /var/www/html

COPY . .

EXPOSE 8000
