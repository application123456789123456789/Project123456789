#!/bin/sh
export PORT=${PORT:-8080}

# Inject port into nginx config
envsubst '${PORT}' < /etc/nginx/nginx-app.conf > /etc/nginx/http.d/default.conf

# Cache Laravel config
php artisan config:cache || true
php artisan route:cache || true
php artisan migrate --force || true

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
