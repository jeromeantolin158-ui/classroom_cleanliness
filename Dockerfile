FROM php:8.3-apache

# PostgreSQL driver for PDO
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Never show PHP errors to visitors
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Silence Apache's "fully qualified domain name" warning
RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && a2enconf servername

COPY . /var/www/html/

# Hosting platforms give the port in $PORT; Apache must listen on it
CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-80}/; s/:80>/:${PORT:-80}>/\" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
