FROM php:8.4-cli

RUN docker-php-ext-install pdo_pgsql

WORKDIR /app

COPY . .

EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t backend/public"]