#!/bin/bash

# Database setup script for eVisa application
# This script creates the database and user with proper permissions

echo "=== eVisa Database Setup ==="
echo ""
echo "This script will:"
echo "1. Create the database 'evisa_db'"
echo "2. Create user 'evisa_user' with password 'EvisaSecure@2026!'"
echo "3. Grant all privileges to the user"
echo ""
echo "You will be prompted for your MySQL root password."
echo ""

# Create SQL commands
SQL_COMMANDS="
-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS evisa_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user if it doesn't exist
CREATE USER IF NOT EXISTS 'evisa_user'@'localhost' IDENTIFIED BY 'EvisaSecure@2026!';

-- Grant all privileges
GRANT ALL PRIVILEGES ON evisa_db.* TO 'evisa_user'@'localhost';

-- Flush privileges
FLUSH PRIVILEGES;

-- Show confirmation
SELECT 'Database and user created successfully!' AS Status;
"

# Execute SQL commands
echo "$SQL_COMMANDS" | sudo mysql

if [ $? -eq 0 ]; then
    echo ""
    echo "✓ Database setup completed successfully!"
    echo ""
    echo "Next steps:"
    echo "1. Run: php artisan migrate"
    echo "2. Run: php artisan db:seed"
    echo ""
else
    echo ""
    echo "✗ Database setup failed. Please check the error messages above."
    echo ""
    exit 1
fi
