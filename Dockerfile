# Use official PHP-Apache image
FROM php:8.3-apache

# Install Node.js and dependencies
RUN apt-get update && apt-get install -y \
    curl \
    libpq-dev \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Set working directory
WORKDIR /var/www/html

# Copy all frontend files into the Apache document root
COPY . /var/www/html/

# Set correct file permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    # Ensure Apache can read all files
    find /var/www/html -type f -exec chmod 644 {} \; && \
    # Ensure directories are executable
    find /var/www/html -type d -exec chmod 755 {} \;

# Enable Apache rewrite module (useful for clean URLs)
RUN a2enmod rewrite

# Expose port 80 for HTTP traffic
EXPOSE 80

# Default to internal Node.js backend in combined container mode
ENV BACKEND_URL="http://localhost:3000"

# Health check (optional but recommended for Render)
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Use startup script to launch both Node.js and Apache
CMD ["bash", "/var/www/html/start.sh"]
