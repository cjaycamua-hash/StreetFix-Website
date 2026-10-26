FROM php:8.3-apache

# Apache modules used by the site's .htaccess (HTTPS redirect, compression, caching)
RUN a2enmod rewrite headers deflate expires \
 && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copy the website
COPY . /var/www/html/

# Writable folders for sessions and cache (Render's disk is temporary)
RUN mkdir -p /var/www/html/config/sessions /var/www/html/config/cache \
 && chown -R www-data:www-data /var/www/html \
 && chmod -R 775 /var/www/html/config/sessions /var/www/html/config/cache

ENV PORT=10000

# On start: rebuild the Firebase key from the FIREBASE_KEY_B64 env var (if set), then run Apache on Render's $PORT
CMD ["/bin/sh", "-c", "if [ -n \"$FIREBASE_KEY_B64\" ]; then echo \"$FIREBASE_KEY_B64\" | base64 -d > /var/www/html/config/firebase-service-account.json && chown www-data:www-data /var/www/html/config/firebase-service-account.json && chmod 640 /var/www/html/config/firebase-service-account.json; fi; sed -i \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -i \"s/:80/:${PORT}/\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
