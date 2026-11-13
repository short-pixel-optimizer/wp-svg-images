# PHP CLI image that runs the suites. Build arg PHP_VERSION selects the runtime
# (for example 7.4, 8.3).
#
# No apt-get here on purpose: the Debian releases behind older PHP images lose
# their package mirrors, which would make the oldest matrix cells unbuildable.
# Official images keep the compilers needed by docker-php-ext-install.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

RUN docker-php-ext-install mysqli pdo_mysql

ADD --chmod=755 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar /usr/local/bin/wp

# Uploaded test files are sniffed with fileinfo and parsed with libxml; fail the
# build early if the image lacks anything the plugin needs at runtime.
RUN php -r 'foreach (["fileinfo","dom","libxml","simplexml","zlib","mysqli"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing extension $e\n"); exit(1); } }'

RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/tests.ini
