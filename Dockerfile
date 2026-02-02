# Simple PHP + nginx container for Render
FROM php:8.2-fpm-alpine

RUN apk add --no-cache nginx bash curl \
  && mkdir -p /run/nginx

# Copy app
WORKDIR /var/www/html
COPY . /var/www/html

# Nginx config
COPY nginx.conf /etc/nginx/nginx.conf

# PHP settings (optional)
RUN docker-php-ext-install opcache || true

# Render provides PORT env var; we must listen on it.
CMD sh -c "sed -i \"s/listen 8080;/listen ${PORT};/\" /etc/nginx/nginx.conf && php-fpm -D && nginx -g 'daemon off;'"
