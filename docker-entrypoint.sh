#!/bin/bash
set -e

PORT="${PORT:-8080}"

# Configure Apache port dynamically (works for Render, Railway, etc.)
sed -ri "s/^Listen [0-9]+$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:[0-9]+>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# If no external MySQL is configured (MYSQL_URL or MYSQLHOST), start bundled MariaDB
if [ -z "$MYSQL_URL" ] && [ -z "$MYSQLHOST" ]; then
    echo "Starting local MariaDB service..."
    service mariadb start || /etc/init.d/mariadb start || true
    mysql -e "CREATE DATABASE IF NOT EXISTS la_varas;" || true
    mysql -e "GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' IDENTIFIED BY '' WITH GRANT OPTION;" || true
    mysql -e "GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' IDENTIFIED BY '' WITH GRANT OPTION;" || true
    mysql -e "FLUSH PRIVILEGES;" || true
    echo "Local MariaDB is ready."
fi

# Run database schema migration & seeding in background
php /var/www/html/init_db.php &

exec apache2-foreground
