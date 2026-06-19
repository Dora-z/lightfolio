#!/bin/sh
set -eu

storage_dir="${LIGHTFOLIO_STORAGE_DIR:-/var/www/storage}"
config_file="${LIGHTFOLIO_CONFIG_FILE:-$storage_dir/config.php}"

mkdir -p "$storage_dir" /var/www/html/uploads/previews /var/www/html/data

if [ -f /var/www/html/data/lightfolio.sqlite ] && [ ! -f "$storage_dir/lightfolio.sqlite" ]; then
  cp /var/www/html/data/lightfolio.sqlite "$storage_dir/lightfolio.sqlite"
fi

config_dir="$(dirname "$config_file")"
mkdir -p "$config_dir"

chown -R www-data:www-data "$storage_dir" "$config_dir" /var/www/html/uploads /var/www/html/data

exec "$@"
