#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [[ ! -f /etc/profile.d/fqj-wp-tests-db.sh ]]; then
	sudo tee /etc/profile.d/fqj-wp-tests-db.sh >/dev/null <<'EOF'
export WP_TESTS_DB_NAME=wordpress_test
export WP_TESTS_DB_USER=root
export WP_TESTS_DB_PASSWORD=root
export WP_TESTS_DB_HOST=127.0.0.1
EOF
fi

composer install --no-interaction
npm ci
