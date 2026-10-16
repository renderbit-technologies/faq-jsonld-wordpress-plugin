#!/usr/bin/env bash
set -euo pipefail

if ! pgrep -x mariadbd >/dev/null 2>&1; then
	sudo service mariadb start
fi

until mariadb -uroot -proot -e "SELECT 1" >/dev/null 2>&1; do
	sleep 1
done

mariadb -uroot -proot -e "CREATE DATABASE IF NOT EXISTS wordpress_test;" >/dev/null 2>&1 || true
