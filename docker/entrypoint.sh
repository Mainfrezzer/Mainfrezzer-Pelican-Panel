#!/bin/ash -e
# shellcheck shell=dash

# check for .env file or symlink and generate app keys if missing
if [ -f /pelican-data/.env ]; then
  echo ".env vars exist."
  # load specific env vars from .env used in the entrypoint and they are not already set
  for VAR in "APP_KEY" "APP_INSTALLED" "DB_CONNECTION" "DB_HOST" "DB_PORT"; do
    echo "checking for ${VAR}"
    ## skip if it looks like it might try to execute code
    if (grep "${VAR}" .env | grep -qE "\$\(|=\`|\$#"); then echo "var in .env may be executable or a comment, skipping"; continue; fi
    # if the variable is in .env then set it
    if (grep -q "${VAR}" .env); then 
      echo "loading ${VAR} from .env"
      export "$(grep "${VAR}" .env | sed 's/"//g')"
      continue
    fi
    ## variable wasn't loaded or in the env to set
    echo "didn't find variable to set"
  done
else
  echo ".env vars don't exist."
  # webroot .env is symlinked to this path
  touch /pelican-data/.env

  # manually generate a key because key generate --force fails
  if [ -z "${APP_KEY}" ]; then
    echo "No key set, Generating key."
    APP_KEY=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 32 | head -n 1)
    echo "APP_KEY=$APP_KEY" > /pelican-data/.env
    echo "Generated app key written to .env file"
  else
    echo "APP_KEY exists in environment, using that."
    echo "APP_KEY=$APP_KEY" > /pelican-data/.env
  fi

  # enable installer
  echo "APP_INSTALLED=false" >> /pelican-data/.env
fi

# create directories for volumes
mkdir -p /pelican-data/database /pelican-data/storage/avatars /pelican-data/storage/fonts /pelican-data/storage/icons /pelican-data/plugins /var/www/html/storage/logs/supervisord 2>/dev/null

# if the app is installed then we need to run migrations on start. New installs will run migrations when you run the installer.
if [ "${APP_INSTALLED}" = "true" ];  then
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
  else
    echo "using sqlite database"
  fi
  
  # run migration
  php artisan migrate --force
fi

echo "Optimizing Filament"
php artisan filament:optimize

# default to caddy not starting
export SUPERVISORD_CADDY=false

echo "Starting PHP-FPM with NGINX"
sed -i "s/client_max_body_size .*/client_max_body_size ${NGINX_UPLOAD};/" /etc/nginx/http.d/default.conf
sed -i "s/client_body_timeout .*/client_body_timeout ${NGINX_TIMEOUT};/" /etc/nginx/http.d/default.conf
sed -i 's#fastcgi_param PHP_VALUE "upload_max_filesize = 100M \\n post_max_size=100M";#fastcgi_param PHP_VALUE "upload_max_filesize = '${NGINX_UPLOAD}' \\n post_max_size='${NGINX_UPLOAD}' \\n memory_limit='${NGINX_UPLOAD}'";#' /etc/nginx/http.d/default.conf
export SUPERVISORD_NGINX=true
echo "Starting Supervisord"
exec "$@"
