# Image for the `setup` service: installs Composer dependencies and downloads
# WordPress. Independent of the PHP version under test; tests/composer.json
# pins the platform to the oldest supported PHP so the result runs everywhere.
FROM php:8.3-cli

RUN apt-get update \
	&& apt-get install -y --no-install-recommends ca-certificates curl unzip subversion libzip-dev \
	&& rm -rf /var/lib/apt/lists/* \
	&& docker-php-ext-install zip

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
