#!/bin/bash
# ==============================================================
# Maseno Retail ERP - System Startup Script
#
# Usage:
#   bash start-system.sh
#
# This script:
#   1. Checks for required tools (psql, php)
#   2. Detects or prompts for PostgreSQL credentials
#   3. Creates the database if it doesn't exist
#   4. Imports sql/schema.sql to initialize all tables
#   5. Starts the PHP built-in development server on port 8080
# ==============================================================

# ── Colors ──
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# ── Project paths ──
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCHEMA_FILE="$PROJECT_DIR/sql/schema.sql"
PHP_PORT="${PORT:-8080}"

echo -e "${CYAN}"
echo "  ╔═══════════════════════════════════════════════╗"
echo "  ║        Maseno Retail ERP - Setup & Launch     ║"
echo "  ║           Supermarket Management System        ║"
echo "  ╚═══════════════════════════════════════════════╝"
echo -e "${NC}"

# ─────────────────────────────────────────────────────────────
# 1. PREREQUISITES CHECK
# ─────────────────────────────────────────────────────────────

echo -e "${BLUE}┌─ Prerequisites Check ──────────────────────────┐${NC}"

# Check PHP
if ! command -v php &> /dev/null; then
    echo -e "${RED}  ✗ PHP is not installed.${NC}"
    echo "  Install it: sudo apt install php php-pgsql php-curl"
    exit 1
fi
echo -e "${GREEN}  ✓ PHP found: $(php -v | head -1)${NC}"

# Check required PHP extensions
for ext in pgsql curl json; do
    if php -m | grep -qi "^$ext$"; then
        echo -e "${GREEN}  ✓ PHP extension: $ext${NC}"
    else
        echo -e "${YELLOW}  ⚠ PHP extension '$ext' not found. Some features may not work.${NC}"
    fi
done

# Check psql
if command -v psql &> /dev/null; then
    echo -e "${GREEN}  ✓ psql found: $(psql --version | head -1)${NC}"
    HAS_PSQL=true
else
    echo -e "${YELLOW}  ⚠ psql not found. Will try PHP-based database setup.${NC}"
    HAS_PSQL=false
fi

echo -e "${BLUE}└──────────────────────────────────────────────────┘${NC}"
echo

# ─────────────────────────────────────────────────────────────
# 2. DATABASE CONFIGURATION
# ─────────────────────────────────────────────────────────────

echo -e "${BLUE}┌─ Database Configuration ───────────────────────┐${NC}"

# Read existing env vars or defaults
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-maseno_retail}"
DB_USER="${DB_USER:-postgres}"
DB_PASS="${DB_PASS:-}"

# Allow override via prompt if env not set
if [ -z "$DB_HOST" ]; then
    read -p "  Database host [localhost]: " input
    DB_HOST="${input:-localhost}"
fi
if [ -z "$DB_PORT" ]; then
    read -p "  Database port [5432]: " input
    DB_PORT="${input:-5432}"
fi
if [ -z "$DB_NAME" ]; then
    read -p "  Database name [maseno_retail]: " input
    DB_NAME="${input:-maseno_retail}"
fi
if [ -z "$DB_USER" ]; then
    read -p "  Database user [postgres]: " input
    DB_USER="${input:-postgres}"
fi
if [ -z "$DB_PASS" ]; then
    read -s -p "  Database password [postgres]: " input
    echo
    DB_PASS="${input:-postgres}"
fi

export DB_HOST DB_PORT DB_NAME DB_USER DB_PASS

echo -e "${GREEN}  ✓ Configuration set${NC}"
echo -e "    Host: ${CYAN}$DB_HOST${NC}  Port: ${CYAN}$DB_PORT${NC}"
echo -e "    Database: ${CYAN}$DB_NAME${NC}  User: ${CYAN}$DB_USER${NC}"

echo -e "${BLUE}└──────────────────────────────────────────────────┘${NC}"
echo

# ─────────────────────────────────────────────────────────────
# 3. DATABASE SETUP
# ─────────────────────────────────────────────────────────────

echo -e "${BLUE}┌─ Database Setup ───────────────────────────────┐${NC}"

# Check connectivity
echo -n "  Checking PostgreSQL connectivity... "
set +e
if PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres -c "SELECT 1" &>/dev/null; then
    echo -e "${GREEN}connected${NC}"
elif PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -c "SELECT 1" &>/dev/null; then
    echo -e "${GREEN}connected (no default db)${NC}"
else
    echo -e "${RED}FAILED${NC}"
    echo -e "${YELLOW}"
    echo "  Could not connect to PostgreSQL at $DB_HOST:$DB_PORT as $DB_USER."
    echo ""
    echo "  Possible fixes:"
    echo "    1. Start PostgreSQL:  sudo systemctl start postgresql"
    echo "    2. Check pg_hba.conf allows connections"
    echo "    3. Set PGPASSWORD env var or edit config/database.php"
    echo "    4. Install PostgreSQL: sudo apt install postgresql postgresql-contrib"
    echo -e "${NC}"
    echo -e "${YELLOW}  ⚠ PostgreSQL is not available. The server will start anyway.${NC}"
    echo "    A setup page will be displayed in the browser."
fi
set -e

# Check if database exists
echo -n "  Checking database '$DB_NAME'... "
set +e
if PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -lqt | cut -d \| -f 1 | grep -qw "$DB_NAME"; then
    echo -e "${GREEN}exists${NC}"
else
    echo -e "${YELLOW}not found, creating...${NC}"
    echo -n "  Creating database '$DB_NAME'... "
    PGPASSWORD="$DB_PASS" createdb -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME" 2>/dev/null || {
        PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres -c "CREATE DATABASE $DB_NAME;" 2>/dev/null || {
            echo -e "${RED}FAILED${NC}"
            echo "  Could not create database. Try:"
            echo "    sudo -u postgres createdb $DB_NAME"
            echo -e "${YELLOW}  ⚠ Continuing without database. The web interface will show setup instructions.${NC}"
        }
    }
    echo -e "${GREEN}created${NC}"
fi
set -e

# Import schema
if [ -f "$SCHEMA_FILE" ]; then
    echo -n "  Importing schema from sql/schema.sql... "
    set +e
    if PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -f "$SCHEMA_FILE" &>/dev/null; then
        echo -e "${GREEN}done${NC}"
    else
        # Schema may already be imported (tables exist) - that's OK
        echo -e "${YELLOW}already imported or minor warnings (OK)${NC}"
    fi
    set -e
else
    echo -e "${YELLOW}  ⚠ Schema file not found at $SCHEMA_FILE${NC}"
    echo "  Creating tables directly via PHP..."
    PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" <<'EOSQL'
    CREATE TABLE IF NOT EXISTS store_config (
        id SERIAL PRIMARY KEY, config_key VARCHAR(128) UNIQUE NOT NULL,
        config_value TEXT NOT NULL, updated_at TIMESTAMPTZ DEFAULT NOW()
    );
    INSERT INTO store_config (config_key, config_value) VALUES
        ('store_name', 'Maseno Retail Supermarket'),
        ('store_phone', '+254700000000'),
        ('store_email', 'info@masenoretail.co.ke'),
        ('currency', 'KES'), ('tax_rate_pct', '16'),
        ('low_stock_threshold', '10'), ('expiry_alert_days', '14'),
        ('mpesa_consumer_key', ''), ('mpesa_consumer_secret', ''),
        ('mpesa_passkey', ''), ('mpesa_shortcode', '174379'), ('mpesa_env', 'sandbox')
    ON CONFLICT (config_key) DO NOTHING;
    SELECT 'Minimal schema created' AS status;
EOSQL
    echo -e "${GREEN}  ✓ Minimal schema created${NC}"
fi

echo -e "${BLUE}└──────────────────────────────────────────────────┘${NC}"
echo

# ─────────────────────────────────────────────────────────────
# 4. START PHP DEVELOPMENT SERVER
# ─────────────────────────────────────────────────────────────

echo -e "${BLUE}┌─ Starting Server ──────────────────────────────┐${NC}"

# Check if port is already in use
if lsof -i :$PHP_PORT &>/dev/null 2>&1; then
    echo -e "${YELLOW}  ⚠ Port $PHP_PORT is already in use.${NC}"
    read -p "  Use a different port? [8081]: " input
    PHP_PORT="${input:-8081}"
fi

# Check database status for final message
set +e
if PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1" &>/dev/null; then
    echo -e "${GREEN}"
    echo "  ✓ All checks passed!"
    echo ""
    echo "  ┌─────────────────────────────────────────────┐"
    echo "  │  Starting Maseno Retail ERP                 │"
    echo "  │                                             │"
    echo "  │  URL:  http://localhost:$PHP_PORT            │"
    echo "  │  Login: admin / admin123                    │"
    echo "  │                                             │"
    echo "  │  Press Ctrl+C to stop the server            │"
    echo "  └─────────────────────────────────────────────┘"
else
    echo -e "${YELLOW}"
    echo "  ⚠ Starting server without database connection."
    echo "    Visit http://localhost:$PHP_PORT to see setup instructions."
    echo ""
    echo "  ┌─────────────────────────────────────────────┐"
    echo "  │  Starting Maseno Retail ERP                 │"
    echo "  │                                             │"
    echo "  │  URL:  http://localhost:$PHP_PORT            │"
    echo "  │  Status: Setup Mode                         │"
    echo "  │                                             │"
    echo "  │  Press Ctrl+C to stop the server            │"
    echo "  └─────────────────────────────────────────────┘"
fi
echo -e "${NC}"
set -e

# Start PHP built-in server
echo -e "${BLUE}Starting PHP server on port $PHP_PORT...${NC}"
cd "$PROJECT_DIR"
PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php \
    -S "0.0.0.0:$PHP_PORT" \
    -t "$PROJECT_DIR" \
    -d "display_errors=1" \
    -d "log_errors=1" \
    -d "error_reporting=E_ALL" \
    -d "max_execution_time=300" \
    -d "memory_limit=256M" \
    "$PROJECT_DIR/server.php"