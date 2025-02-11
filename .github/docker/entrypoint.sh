#!/bin/ash -e

#mkdir -p /var/log/supervisord/ /var/log/php8/ \

## check for .env file and generate app keys if missing
if [ -f /pelican-data/.env ]; then
  echo "external vars exist."
  rm -rf /var/www/html/.env
else
  echo "external vars don't exist."
  rm -rf /var/www/html/.env
  touch /pelican-data/.env

  ## manually generate a key because key generate --force fails
  if [ -z $APP_KEY ]; then
     echo -e "Generating key."
     APP_KEY=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 32 | head -n 1)
     echo -e "Generated app key: $APP_KEY"
     echo -e "APP_KEY=$APP_KEY" > /pelican-data/.env
  else
    echo -e "APP_KEY exists in environment, using that."
    echo -e "APP_KEY=$APP_KEY" > /pelican-data/.env
  fi

  ## enable installer
  echo -e "APP_INSTALLED=false" >> /pelican-data/.env
fi

mkdir /pelican-data/database
ln -s /pelican-data/.env /var/www/html/
chown -h www-data:www-data /var/www/html/.env
ln -s /pelican-data/database/database.sqlite /var/www/html/database/

if ! grep -q "APP_KEY=" .env || grep -q "APP_KEY=$" .env; then
  echo "Generating APP_KEY..."
  su -s /bin/ash -c "php artisan key:generate --force" www-data
else
  echo "APP_KEY is already set."
fi

## make sure the db is set up
echo -e "Migrating Database"
su -s /bin/ash -c "php artisan migrate --force" www-data

echo -e "Optimizing Filament"
su -s /bin/ash -c "php artisan filament:optimize" www-data

## start cronjobs for the queue
echo -e "Starting cron jobs."
crond -L /var/log/crond -l 5

export SUPERVISORD_CADDY=false

#Placeholder work around

## disable caddy if SKIP_CADDY is set
#if [[ "${SKIP_CADDY:-}" == "true" ]]; then
#  echo "Starting PHP-FPM with NGINX"
#  cp /var/www/html/.github/docker/magnon.conf /etc/nginx/http.d/default.conf
#  nginx
#else
#  echo "Starting PHP-FPM and Caddy"
#  export SUPERVISORD_CADDY=true
#fi

echo "Starting PHP-FPM with NGINX"
cp /var/www/html/.github/docker/magnon.conf /etc/nginx/http.d/default.conf
nginx

chown -R www-data:www-data /pelican-data/.env /pelican-data/database
#Ensure Perms are correct
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html/storage/* /var/www/html/bootstrap/cache/
echo "Starting Supervisord"
exec "$@"
