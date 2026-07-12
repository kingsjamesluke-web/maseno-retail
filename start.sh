#!/bin/bash
set -e

# Install Node.js dependencies if needed
if [ -f package.json ]; then
    npm install --production
fi

# Start Node.js backend in background
node server.js &
NODE_PID=$!

# Wait for Node.js to be ready
echo "Waiting for Node.js backend to start..."
for i in {1..30}; do
    if curl -s http://localhost:3000/health > /dev/null 2>&1; then
        echo "✓ Node.js backend is running on port 3000"
        break
    fi
    if [ $i -eq 30 ]; then
        echo "✗ Node.js backend failed to start"
        kill $NODE_PID
        exit 1
    fi
    sleep 1
done

# Enable default site and start Apache in foreground
echo "Starting Apache..."
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite 000-default >/dev/null 2>&1 || true
exec apache2-foreground
