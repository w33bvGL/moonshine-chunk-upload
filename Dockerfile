FROM php:8.4-cli-alpine

RUN apk add --no-cache \
        git \
        unzip \
        sqlite \
        sqlite-dev \
        icu-libs \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        libpng-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite intl mbstring zip gd bcmath exif \
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/*

RUN printf "post_max_size=64M\nupload_max_filesize=64M\nmemory_limit=512M\n" \
    > /usr/local/etc/php/conf.d/chunk-upload.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/sandbox

COPY .docker/sandbox-entrypoint.sh /usr/local/bin/sandbox-entrypoint
RUN chmod +x /usr/local/bin/sandbox-entrypoint

EXPOSE 8000

ENTRYPOINT ["sandbox-entrypoint"]
