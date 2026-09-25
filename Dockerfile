FROM php:8.1-alpine3.17 AS build-env

LABEL owner="Giancarlos Salas"
LABEL maintainer="me@giansalex.dev"

WORKDIR /app
ENV APP_ENV prod
ENV COMPOSER_ALLOW_SUPERUSER=1

# Install php dev dependencies
RUN apk add --no-cache \
    git \
    unzip \
    curl \
    libxml2-dev

# Install php extensions
RUN docker-php-ext-install soap && \
    docker-php-ext-configure opcache --enable-opcache && \
    docker-php-ext-install opcache && \
    docker-php-ext-install pcntl

COPY . .

# Install Packages
# Solo `composer install`: la imagen lleva exactamente las versiones de composer.lock, las mismas
# con las que corren los tests. php-pm esta en composer.json; antes se agregaba aca con un
# `composer require --with-all-dependencies`, que resolvia de nuevo en cada build y dejaba 24
# paquetes con otra version que el lock (y podia traer un greenter/xml nuevo sin revisar la
# plantilla de la guia 31, ver la skill greenter).
RUN curl --silent --show-error -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    composer install --no-interaction --no-dev --no-autoloader --no-scripts --no-progress --ignore-platform-reqs && \
    composer dump-autoload --optimize --no-dev --classmap-authoritative && \
    composer dump-env prod --empty && \
    find -type f -name '*.md' -delete;
#   twig have Test as src code
#   find -name "[Tt]est*" -type d -exec rm -rf {} +

FROM surnet/alpine-wkhtmltopdf:3.17.0-0.12.6-small as pdf-bin

FROM php:8.1-alpine3.17

EXPOSE 8000
WORKDIR /var/www/html

ENV APP_ENV prod
ENV APP_SECRET c4136a0540553455b122461ab6923e9d
ENV WKHTMLTOPDF_PATH wkhtmltopdf
ENV CORS_ALLOW_ORIGIN .
ENV TRUSTED_PROXIES="127.0.0.1,REMOTE_ADDR"
# Sin valores por defecto para el token, las credenciales SOL ni las URLs de SUNAT: la imagen
# traia los de prueba (MODDATOS, beta, sandbox GRE), y una variable olvidada en produccion
# mandaba en silencio al beta. Se pasan al correr el contenedor; docker-entrypoint.sh no
# arranca si falta alguna. Los valores de prueba estan en .env (y en .env.test).

ARG PHP_EXT_DIR=/usr/local/lib/php/extensions/no-debug-non-zts-20210902

# Install wkhtmltopdf deps
RUN apk update && apk add --no-cache \
        libstdc++ \
        libx11 \
        libxrender \
        libxext \
        libssl1.1 \
        ca-certificates \
        fontconfig \
        freetype \
        ttf-droid

COPY --from=build-env $PHP_EXT_DIR $PHP_EXT_DIR
COPY --from=build-env $PHP_INI_DIR/conf.d/ $PHP_INI_DIR/conf.d/
COPY --from=build-env /app .
COPY --from=pdf-bin /bin/wkhtmltopdf /usr/bin/
COPY docker/config/* $PHP_INI_DIR/conf.d/
COPY docker/docker-entrypoint.sh .
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    php bin/console cache:clear && \
    chmod -R 755 ./data

ENTRYPOINT ["sh", "./docker-entrypoint.sh"]
