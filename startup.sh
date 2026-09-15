#!/bin/sh

set -euo pipefail

echo "Startup script is running..." > /var/log/startup.log

# Default the PUID and PGID environment variables to 82, otherwise
# set to the user defined ones.
PUID=${PUID:-82}
PGID=${PGID:-82}

# When WALLOS_DATA_DIR is set (for example, to a Render Persistent Disk), keep
# the database and user-uploaded logos on that volume while preserving the
# paths expected by Wallos. Existing persistent data always wins; application
# defaults are copied only into an entirely empty destination.
prepare_persistent_path() {
  app_path=$1
  data_path=$2

  mkdir -p "$data_path"

  if [ -d "$app_path" ] && [ ! -L "$app_path" ] && [ -z "$(ls -A "$data_path")" ]; then
    cp -a "$app_path/." "$data_path/"
  fi

  if [ -L "$app_path" ]; then
    current_target=$(readlink "$app_path")
    if [ "$current_target" = "$data_path" ]; then
      return
    fi
  fi

  rm -rf "$app_path"
  ln -s "$data_path" "$app_path"
}

if [ -n "${WALLOS_DATA_DIR:-}" ]; then
  case "$WALLOS_DATA_DIR" in
    /*) ;;
    *) echo "WALLOS_DATA_DIR must be an absolute path" >&2; exit 1 ;;
  esac

  mkdir -p "$WALLOS_DATA_DIR"
  prepare_persistent_path /var/www/html/db "$WALLOS_DATA_DIR/db"
  prepare_persistent_path /var/www/html/images/uploads/logos "$WALLOS_DATA_DIR/logos"
fi

# Change the www-data user id and group id to be the user-specified ones
groupmod -o -g "$PGID" www-data
usermod -o -u "$PUID" www-data
chown -R www-data:www-data /var/www/html
if [ -n "${WALLOS_DATA_DIR:-}" ]; then
  chown -R www-data:www-data "$WALLOS_DATA_DIR"
fi
chown -R www-data:www-data /tmp
chmod -R 770 /tmp

# PIDs we’ll track
PHP_FPM_PID=
NGINX_PID=
CROND_PID=
shutdown_in_progress=0

shutdown_once() {
  exit_signal=$?
  kill_signal=$(kill -l "$exit_signal" 2>/dev/null || echo "$exit_signal")

  [ "$shutdown_in_progress" -eq 1 ] && return 0
  shutdown_in_progress=1

  echo "Got signal: $kill_signal - Shutting down gracefully... "
  # nginx wants QUIT for graceful
  nginx -s quit || true
  # php-fpm graceful quit as well
  [ -n "${PHP_FPM_PID}" ] && kill -QUIT "${PHP_FPM_PID}" 2>/dev/null || true
  # cron can just get TERM
  [ -n "${CROND_PID}" ] && kill -TERM "${CROND_PID}" 2>/dev/null || true
  echo "Graceful shutdown complete."
}

# Handle all common stop signals
trap 'shutdown_once' SIGTERM SIGINT SIGQUIT

touch ~/startup.txt

# Create database if it does not exist
/usr/local/bin/php /var/www/html/endpoints/cronjobs/createdatabase.php

# Perform any database migrations
/usr/local/bin/php /var/www/html/endpoints/db/migrate.php

# Change permissions on the database directory
chmod -R 755 /var/www/html/db/
chown -R www-data:www-data /var/www/html/db/

mkdir -p /var/www/html/images/uploads/logos/avatars

# Change permissions on the logos directory
chmod -R 755 /var/www/html/images/uploads/logos
chown -R www-data:www-data /var/www/html/images/uploads/logos

# Run updatenextpayment.php and wait for it to finish
/usr/local/bin/php /var/www/html/endpoints/cronjobs/updatenextpayment.php

# Run updateexchange.php
/usr/local/bin/php /var/www/html/endpoints/cronjobs/updateexchange.php

# Run checkforupdates.php
/usr/local/bin/php /var/www/html/endpoints/cronjobs/checkforupdates.php

# Start serving only after initialization is complete. In particular, cron must
# not race the migration chain for SQLite locks during a deploy.
echo "Launching php-fpm"
php-fpm -F &
PHP_FPM_PID=$!

echo "Launching crond"
crond -f &
CROND_PID=$!

echo "Launching nginx"
nginx -g 'daemon off;' &
NGINX_PID=$!

# dcron loads this root crontab at startup. Removing the spool file after it is
# loaded prevents duplicate executions while the running daemon retains them.
sleep 1
crontab -d -u root

# Essentially wait until all child processes exit
wait
