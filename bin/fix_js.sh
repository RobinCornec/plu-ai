#!/bin/bash

# This script fixes the JavaScript functionality by updating the asset mapping and clearing the cache

echo "Fixing JavaScript functionality..."

# Clear the Symfony cache
echo "Clearing cache..."
php bin/console cache:clear

# Install assets in the public directory
echo "Installing assets..."
php bin/console assets:install public

# Update the importmap
echo "Updating importmap..."
php bin/console importmap:install

echo "Done! The JavaScript functionality should now work correctly."
echo "If it still doesn't work, try refreshing the page or clearing your browser cache."
