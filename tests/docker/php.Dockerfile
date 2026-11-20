# PHP CLI image that runs the suites. Build arg PHP_VERSION selects the runtime
# (for example 7.4, 8.3).
#
# No apt-get here on purpose: the Debian releases behind older PHP images lose
# their package mirrors, which would make the oldest matrix cells unbuildable.
# Official images keep the compilers needed by docker-php-ext-install.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

RUN docker-php-ext-install mysqli pdo_mysql

# WP-CLI at a fixed release, verified by its published checksum, so a new
# WP-CLI version cannot change the results of the `cli` suite unnoticed. Bump
# the version and the checksum together.
ADD --chmod=755 \
	--checksum=sha256:ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c \
	https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar /usr/local/bin/wp

# Uploaded test files are sniffed with fileinfo and parsed with libxml; fail the
# build early if the image lacks anything the plugin needs at runtime.
RUN php -r 'foreach (["fileinfo","dom","libxml","simplexml","zlib","mysqli"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing extension $e\n"); exit(1); } }'

RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/tests.ini
