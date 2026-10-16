# Test image: Apache + the requested PHP version + WP-CLI. WordPress itself is
# downloaded at run time (tests/matrix/container-run.sh) so any PHP x WP pair works.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-apache

RUN set -eux; \
	. /etc/os-release; \
	case "$VERSION_CODENAME" in \
		buster|bullseye) \
			printf 'deb http://archive.debian.org/debian %s main\ndeb http://archive.debian.org/debian-security %s-security main\n' "$VERSION_CODENAME" "$VERSION_CODENAME" > /etc/apt/sources.list; \
			echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive ;; \
	esac; \
	apt-get update; \
	apt-get install -y --no-install-recommends libzip-dev libbz2-dev unzip curl ca-certificates less; \
	docker-php-ext-install -j"$(nproc)" mysqli zip bz2; \
	a2enmod rewrite; \
	rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp-cli.phar \
	&& php /usr/local/bin/wp-cli.phar --allow-root --version

WORKDIR /var/www/html
