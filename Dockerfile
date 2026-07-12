# Use official PHP-Apache image
FROM php:8.3-apache

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

# environment variable BACKEND_URL can be set at runtime
# Example: docker run -e BACKEND_URL=https://your-backend.onrender.com ...
ENV BACKEND_URL=""

# Health check (optional but recommended for Render)
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Start Apache in foreground
CMD ["apache2-foreground"]