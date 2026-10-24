FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . .
ENV APP_ENV=production PHP_CLI_SERVER_WORKERS=4
EXPOSE 10000
# Crea las tablas y datos iniciales si hace falta (idempotente) y arranca la API
CMD ["sh", "-c", "php scripts/setup.php && exec php -S 0.0.0.0:${PORT:-10000} -t public public/index.php"]
