FROM ubuntu:24.04

ARG NODE_VERSION=22.14.0

RUN apt-get update \
	&& DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
		ca-certificates curl git unzip xz-utils \
		php8.3-cli php8.3-mysqli php8.3-xml php8.3-mbstring \
		mariadb-server \
		sudo \
	&& rm -rf /var/lib/apt/lists/* \
	&& curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-x64.tar.xz" \
		| tar -xJ -C /usr/local --strip-components=1 --no-same-owner \
	&& curl -sS https://getcomposer.org/installer \
		| php -- --install-dir=/usr/local/bin --filename=composer

# Local test database credentials match CI and CONTRIBUTING.md.
RUN service mariadb start \
	&& mariadb -e "ALTER USER 'root'@'localhost' IDENTIFIED BY 'root'; CREATE DATABASE IF NOT EXISTS wordpress_test; FLUSH PRIVILEGES;" \
	&& (service mariadb stop || true)

RUN printf '%s\n' \
	'export WP_TESTS_DB_NAME=wordpress_test' \
	'export WP_TESTS_DB_USER=root' \
	'export WP_TESTS_DB_PASSWORD=root' \
	'export WP_TESTS_DB_HOST=127.0.0.1' \
	> /etc/profile.d/fqj-wp-tests-db.sh

RUN echo 'ubuntu ALL=(ALL) NOPASSWD: /usr/sbin/service mariadb *' > /etc/sudoers.d/mariadb \
	&& chmod 440 /etc/sudoers.d/mariadb

USER ubuntu
