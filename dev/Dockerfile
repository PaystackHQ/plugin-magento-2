FROM php:8.4-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git unzip curl \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
    libicu-dev libxml2-dev libxslt-dev libzip-dev libonig-dev \
    libsodium-dev default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Magento 2
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    bcmath ftp gd intl mbstring pdo_mysql soap xsl zip sockets sodium opcache

# Enable Apache modules
RUN a2enmod rewrite headers

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure PHP for Magento
RUN echo "memory_limit = 2G" > /usr/local/etc/php/conf.d/magento.ini \
    && echo "max_execution_time = 1800" >> /usr/local/etc/php/conf.d/magento.ini \
    && echo "realpath_cache_size = 10M" >> /usr/local/etc/php/conf.d/magento.ini \
    && echo "realpath_cache_ttl = 7200" >> /usr/local/etc/php/conf.d/magento.ini

WORKDIR /var/www/html

# Download Magento via Mage-OS public mirror (no Adobe marketplace auth needed)
RUN composer config --global audit.block-insecure false \
    && composer create-project \
    --repository-url=https://mirror.mage-os.org/ \
    magento/project-community-edition=2.4.8-p3 . \
    --no-dev --no-interaction

# Point Apache to Magento's pub/ directory
RUN sed -i 's|/var/www/html|/var/www/html/pub|g' /etc/apache2/sites-available/000-default.conf
RUN printf '<Directory /var/www/html/pub>\n    AllowOverride All\n</Directory>\n' >> /etc/apache2/apache2.conf

# Disable CSP enforcement for local development
# (Magento 2.4.8 CSP blocks its own inline scripts without this)
RUN echo 'Header unset Content-Security-Policy' >> /etc/apache2/apache2.conf

# Fix permissions
RUN chown -R www-data:www-data /var/www/html

COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
