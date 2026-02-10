#!/bin/ash -e
# check for .env file or symlink and generate app keys if missing
if [ -f /var/www/html/.env ]; then
  echo "external vars exist."
  # load specific env vars from .env used in the entrypoint and they are not already set
  #for VAR in "APP_KEY" "APP_INSTALLED" "DB_CONNECTION" "DB_HOST" "DB_PORT"; do if ! (printenv | grep -q ${VAR}); then export $(grep ${VAR} .env | grep -ve "^#"); fi; done
  . /var/www/html/.env
else
  echo "external vars don't exist."
  # webroot .env is symlinked to this path
  touch /pelican-data/.env

  # manually generate a key because key generate --force fails
  if [ -z ${APP_KEY} ]; then
    echo -e "Generating key."
    APP_KEY=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 32 | head -n 1)
    echo -e "Generated app key: $APP_KEY"
    echo -e "APP_KEY=$APP_KEY" > /pelican-data/.env
  else
    echo -e "APP_KEY exists in environment, using that."
    echo -e "APP_KEY=$APP_KEY" > /pelican-data/.env
  fi

  # enable installer
  echo -e "APP_INSTALLED=false" >> /pelican-data/.env
fi

# create directories for volumes
mkdir -p /pelican-data/database /pelican-data/storage/avatars /pelican-data/storage/fonts /pelican-data/storage/icons /pelican-data/plugins /var/www/html/storage/logs/supervisord 2>/dev/null

# if the app is installed then we need to run migrations on start. New installs will run migrations when you run the installer.
if [ "${APP_INSTALLED}" == "true" ];  then
  #if the db is anything but sqlite wait until it's accepting connections
  if [ "${DB_CONNECTION}" != "sqlite" ]; then
    # check for DB up before starting the panel
    echo "Checking database status."
    until nc -z -v -w30 $DB_HOST $DB_PORT
    do
      echo "Waiting for database connection..."
      # wait for 1 seconds before check again
      sleep 1
    done
  fi
  # run migration
  php artisan migrate --force
fi

echo -e "Optimizing Filament"
php artisan filament:optimize


echo "Starting PHP-FPM with NGINX"
sed -i "s/client_max_body_size .*/client_max_body_size ${NGINX_UPLOAD};/" /etc/nginx/http.d/default.conf
sed -i "s/client_body_timeout .*/client_body_timeout ${NGINX_TIMEOUT};/" /etc/nginx/http.d/default.conf
sed -i 's#fastcgi_param PHP_VALUE "upload_max_filesize = 100M \\n post_max_size=100M";#fastcgi_param PHP_VALUE "upload_max_filesize = '${NGINX_UPLOAD}' \\n post_max_size='${NGINX_UPLOAD}' \\n memory_limit='${NGINX_UPLOAD}'";#' /etc/nginx/http.d/default.conf
export SUPERVISORD_NGINX=true
echo "Starting Supervisord"
exec "$@"
