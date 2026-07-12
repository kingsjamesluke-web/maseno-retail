require('dotenv').config();
const { Client } = require('pg');
const express = require('express');

const app = express();
const PORT = process.env.PORT || 3000;

// Allow CORS for frontend integration
app.use((req, res, next) => {
  res.header('Access-Control-Allow-Origin', '*');
  res.header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
  res.header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
  if (req.method === 'OPTIONS') return res.sendStatus(200);
  next();
});

app.use(express.json());

// Neon Database Connection
const client = new Client({
  connectionString: process.env.DATABASE_URL,
});

// Test database connection and start server
async function startServer() {
  try {
    await client.connect();
    console.log('✓ Connected to Neon database successfully');

    // Test query
    const result = await client.query('SELECT NOW()');
    console.log('✓ Database test query successful:', result.rows[0].now);

    await client.end();
    console.log('✓ Database connection closed');

    // Start Express server with error handling
    const server = app.listen(PORT, () => {
      console.log(`✓ Server running on http://localhost:${PORT}`);
      console.log('✓ Ready for PHP integration');
    });

    server.on('error', (e) => {
      if (e.code === 'EADDRINUSE') {
        console.error(`✗ Port ${PORT} is already in use`);
        console.log(`  Try: lsof -i :${PORT} to find the process`);
        console.log(`  Or change PORT in .env file`);
        process.exit(1);
      } else {
        console.error('✗ Server error:', e.message);
        process.exit(1);
      }
    });

  } catch (error) {
    console.error('✗ Database connection failed:', error.message);
    process.exit(1);
  }
}

// Basic route
app.get('/', (req, res) => {
  res.json({
    status: 'success',
    message: 'Maseno Retail System - Neon Database Connected',
    database: 'Neon PostgreSQL',
    phpReady: true,
    timestamp: new Date().toISOString()
  });
});

// Health check route
app.get('/health', (req, res) => {
  res.json({ status: 'ok', database: 'Neon' });
});

startServer();