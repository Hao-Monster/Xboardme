#!/bin/bash

set -Eeuo pipefail

if [ ! -d ".git" ]; then
  echo "Please deploy using Git."
  exit 1
fi

if ! command -v git &> /dev/null; then
    echo "Git is not installed! Please install git and try again."
    exit 1
fi

repo_root="$(pwd)"

add_safe_directory() {
  local dir="$1"

  git config --global --get-all safe.directory | grep -Fx "$dir" > /dev/null ||     git config --global --add safe.directory "$dir"
}

add_safe_directory "$repo_root"
add_safe_directory "$repo_root/public/assets/admin"

git fetch origin master
git pull --ff-only origin master
rm -f composer.phar
wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
git submodule update --init --recursive --force
php composer.phar install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative
php composer.phar check-platform-reqs --no-dev
php composer.phar audit --locked --no-interaction
php artisan xboard:update

if [ -f "/etc/init.d/bt" ] || [ -f "/.dockerenv" ]; then
  chown -R www:www $(pwd);
fi

if [ -d ".docker/.data" ]; then
  chmod -R 777 .docker/.data
fi
