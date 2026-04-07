#!/bin/bash
set -e

# Only run Magento setup on first boot
if [ ! -f /var/www/html/app/etc/env.php ]; then
    echo "==> Waiting for database to be ready..."
    until php -r "new PDO('mysql:host=paystack-db;dbname=magento', 'magento', 'magento');" 2>/dev/null; do
        sleep 2
    done

    echo "==> Installing Magento..."
    php bin/magento setup:install \
        --base-url=http://localhost:8080 \
        --db-host=paystack-db \
        --db-name=magento \
        --db-user=magento \
        --db-password=magento \
        --search-engine=opensearch \
        --opensearch-host=paystack-search \
        --opensearch-port=9200 \
        --admin-firstname=Admin \
        --admin-lastname=MyStore \
        --admin-email=admin@example.com \
        --admin-user=admin \
        --admin-password='Admin12345!' \
        --backend-frontname=admin \
        --language=en_US \
        --currency=NGN \
        --timezone=Africa/Lagos \
        --use-rewrites=1 \
        --cleanup-database

    chown -R www-data:www-data /var/www/html
    echo "==> Magento installation complete."
fi

exec "$@"
