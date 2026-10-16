#!/bin/bash
# Prepares a Claude Code cloud session so linters and tests can run:
# Composer + npm dependencies, plus a local MariaDB for the WordPress PHPUnit suite.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(pwd)}"

# Database settings read by tests/php/wp-tests-config.php.
DB_NAME="wordpress_test"
DB_USER="root"
DB_PASS="root"

# --- PHP dependencies (phpcs, phpunit, WordPress test suite) ---
# Cloud sessions run as root; without this Composer disables plugins, so the
# phpcs installer plugin never registers the WordPress coding standard.
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-interaction --no-progress --prefer-dist

# --- JS dependencies (jest) ---
npm install --no-audit --no-fund

# --- MariaDB for the PHPUnit integration tests ---
if ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq mariadb-server
fi

if ! mysqladmin ping --silent >/dev/null 2>&1; then
  service mariadb start 2>/dev/null || service mysql start
  for _ in $(seq 1 30); do
    mysqladmin ping --silent >/dev/null 2>&1 && break
    sleep 1
  done
fi

# Idempotent: password-authenticated root over TCP and a fresh test database.
if ! mysql -u"$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS $DB_NAME" >/dev/null 2>&1; then
  mysql -uroot <<SQL
ALTER USER '$DB_USER'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('$DB_PASS');
CREATE DATABASE IF NOT EXISTS $DB_NAME;
FLUSH PRIVILEGES;
SQL
fi

# Persist DB settings for the session so `vendor/bin/phpunit` works as-is.
if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
  {
    echo 'export WP_TESTS_DB_HOST="127.0.0.1:3306"'
    echo "export WP_TESTS_DB_USER=\"$DB_USER\""
    echo "export WP_TESTS_DB_PASSWORD=\"$DB_PASS\""
    echo "export WP_TESTS_DB_NAME=\"$DB_NAME\""
    echo "export PATH=\"$PWD/vendor/bin:\$PATH\""
  } >> "$CLAUDE_ENV_FILE"
fi
