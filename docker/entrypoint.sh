#!/bin/sh
set -e

echo "Waiting for MySQL..."
MAX_TRIES=30
TRIES=0
until php -r "
    \$h = getenv('DB_HOST') ?: 'db';
    \$p = getenv('DB_PORT') ?: '3306';
    \$d = getenv('DB_DATABASE') ?: 'pepper_escrow';
    \$u = getenv('DB_USERNAME') ?: 'root';
    \$pw = getenv('DB_PASSWORD') ?: '';
    new PDO(\"mysql:host=\$h;port=\$p;dbname=\$d\", \$u, \$pw);
    echo 'ok';
" 2>/dev/null | grep -q 'ok'; do
    TRIES=$((TRIES+1))
    if [ "$TRIES" -ge "$MAX_TRIES" ]; then
        echo "MySQL did not become ready in time."
        exit 1
    fi
    sleep 2
done
echo "MySQL is ready."

php artisan migrate --force

if [ "${APP_SEED:-false}" = "true" ]; then
    php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache || true
php artisan view:cache

# Everything above runs as root, so the caches it writes and any log it
# touches end up root-owned. php-fpm runs as www-data and then cannot write
# to them. The failure is quiet and confusing: requests that log anything
# return 500 with nothing in the log, because the logging is what throws.
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/laravel.conf
