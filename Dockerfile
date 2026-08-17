FROM phpswoole/swoole:6.1.0-php8.3-alpine@sha256:895033ef1b965458c81ed55a22eaf9b9ec2e2b0b8694942c7fdd731ff6f099d9

COPY --from=mlocati/php-extension-installer:2.11.12@sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138 /usr/bin/install-php-extensions /usr/local/bin/

# Install only extensions missing from the pinned Swoole image. Building them
# in one transaction avoids repeatedly downloading the compiler toolchain;
# conservative optimization keeps the existing ARM64 compatibility posture.
RUN CFLAGS="-O0 -g0" install-php-extensions pcntl bcmath zip && \
    apk --no-cache add shadow sqlite mysql-client mysql-dev mariadb-connector-c git patch supervisor redis caddy su-exec && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

COPY composer.json composer.lock /www/

# Dependency downloads are keyed only by the locked manifests. The GitHub
# token exists solely in this BuildKit secret mount and is converted to
# COMPOSER_AUTH in the process environment, never written into an image layer.
RUN --mount=type=secret,id=github_token \
    if [ -f /run/secrets/github_token ]; then \
        github_token="$(tr -d '\r\n' < /run/secrets/github_token)"; \
        export COMPOSER_AUTH="$(printf '{"github-oauth":{"github.com":"%s"}}' "$github_token")"; \
    fi; \
    installed=false; \
    for attempt in 1 2; do \
        if composer install --no-cache --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader; then \
            installed=true; \
            break; \
        fi; \
        sleep $((attempt * 20)); \
    done; \
    if [ "$installed" != true ]; then \
        composer install --no-cache --no-dev --no-interaction --no-progress --prefer-source --no-scripts --no-autoloader; \
    fi; \
    find /www/vendor -type d -name .git -prune -exec rm -rf '{}' +

COPY .docker /
COPY . /www

COPY .docker/supervisor/supervisord.conf /etc/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini

RUN composer dump-autoload --no-dev --no-interaction --classmap-authoritative \
    && composer check-platform-reqs --no-dev \
    && php artisan storage:link \
    && chown -R www:www /www \
    && chmod -R 775 /www \
    && mkdir -p /data \
    && chown redis:redis /data
    
ENV RUNTIME_INSTANCE_ID=default \
    ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true \
    ENABLE_SCHEDULER=true

EXPOSE 7001
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
