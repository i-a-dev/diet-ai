FROM php:8.3-cli-alpine

RUN apk add --no-cache sqlite-dev curl-dev \
    && docker-php-ext-install pdo_sqlite curl

WORKDIR /var/www/html

COPY . .

EXPOSE 8000
