FROM dunglas/frankenphp:php8.4

# pdo_sqlite ya viene en la imagen. Agregamos phpredis (el XADD/XREAD) y opcache.
RUN install-php-extensions redis opcache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-sse.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/demo-entrypoint
RUN chmod +x /usr/local/bin/demo-entrypoint

WORKDIR /app

# El codigo se monta con un bind mount desde docker-compose, asi que no lo
# copiamos: es una maqueta y se espera editarla en caliente.

EXPOSE 8000

ENTRYPOINT ["demo-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
