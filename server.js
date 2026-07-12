require('dotenv').config();
const { Client } = require('pg');
const express = require('express');

const app = express();
// Always run Node.js backend on port 3000 internally, regardless of Render's PORT env var
const PORT = 3000;

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

// Handle unexpected PostgreSQL connection drops without crashing
client.on('error', (err) => {
  console.error('Unexpected error on pg client:', err.message);
});

// Global error handlers to prevent process crashes
process.on('unhandledRejection', (err) => {
  console.error('Unhandled rejection:', err.message);
});

process.on('uncaughtException', (err) => {
  console.error('Uncaught exception:', err.message);
});

// Run auto-migration if database is empty
async function runMigration() {
  try {
    const check = await client.query(
      "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'users')"
    );
    const usersExists = check.rows[0].exists;

    if (!usersExists) {
      console.log('⚙ Running database migration...');
      const fs = require('fs');
      const schemaPath = require('path').join(__dirname, 'sql', 'schema.sql');
      const schema = fs.readFileSync(schemaPath, 'utf8');
      
      // Split by semicolons and execute each statement individually
      const statements = schema.split(';').map(s => s.trim()).filter(s => s.length > 0);
      for (const stmt of statements) {
        try {
          await client.query(stmt);
        } catch (stmtError) {
          // Skip COMMIT/ BEGIN-only statements if they fail
          if (!stmt.toLowerCase().startsWith('commit') && !stmt.toLowerCase().startsWith('begin')) {
            throw new Error(`Failed to execute: ${stmt.substring(0, 100)}... - ${stmtError.message}`);
          }
        }
      }
      console.log('✓ Database schema applied successfully');
    } else {
      console.log('✓ Database schema already exists');
      
      // Ensure default admin user exists
      const userCheck = await client.query("SELECT id FROM users WHERE username = 'admin'");
      if (userCheck.rows.length === 0) {
        await client.query(
          "INSERT INTO users (username, password_hash, full_name, role, phone) VALUES ('admin', 'admin123', 'System Admin', 'admin', '+254700000001') ON CONFLICT (username) DO NOTHING"
        );
        console.log('✓ Default admin user created (username: admin, password: admin123)');
      }
    }
  } catch (error) {
    console.error('✗ Migration failed:', error.message);
    throw error;
  }
}

// Test database connection and start server
async function startServer() {
  try {
    await client.connect();
    console.log('✓ Connected to Neon database successfully');

    // Test query
    const result = await client.query('SELECT NOW()');
    console.log('✓ Database test query successful:', result.rows[0].now);

    // Auto-migrate schema if needed
    await runMigration();

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

// Authentication routes
app.post('/api/auth/login', async (req, res) => {
  try {
    const { username, password } = req.body;
    if (!username || !password) {
      return res.json({ success: false, message: 'Username and password required.' });
    }

    const result = await client.query(
      'SELECT id, username, password_hash, full_name, role, phone, email, is_active FROM users WHERE username = $1',
      [username]
    );
    const user = result.rows[0];

    if (!user) {
      return res.json({ success: false, message: 'Invalid username or password.' });
    }

    if (!user.is_active) {
      return res.json({ success: false, message: 'Account is disabled. Contact admin.' });
    }

    // Demo mode: accept 'admin123' for any active user
    if (password !== 'admin123') {
      return res.json({ success: false, message: 'Invalid username or password.' });
    }

    const responseUser = {
      id: user.id,
      username: user.username,
      full_name: user.full_name,
      role: user.role,
      phone: user.phone,
      email: user.email,
    };

    res.json({ success: true, user: responseUser, message: 'Login successful.' });
  } catch (error) {
    console.error('Login error:', error.message);
    res.json({ success: false, message: 'Login failed: ' + error.message });
  }
});

// Shift routes
app.post('/api/shifts/open', async (req, res) => {
  try {
    const { user_id, opening_float, notes } = req.body;
    const result = await client.query(
      'INSERT INTO cashier_shifts (user_id, opening_float, notes) VALUES ($1, $2, $3) RETURNING id',
      [user_id, opening_float || 0, notes || '']
    );
    const shiftId = result.rows[0].id;
    res.json({ success: true, shift_id: shiftId, message: 'Shift opened successfully.' });
  } catch (error) {
    res.json({ success: false, message: 'Failed to open shift: ' + error.message });
  }
});

app.get('/api/shifts/current', async (req, res) => {
  try {
    const userId = req.query.user_id;
    if (!userId) {
      return res.json({ success: false, shift: null });
    }
    const result = await client.query(
      'SELECT * FROM cashier_shifts WHERE user_id = $1 AND status = \'open\' ORDER BY id DESC LIMIT 1',
      [userId]
    );
    const shift = result.rows[0] || null;
    res.json({ success: true, shift: shift });
  } catch (error) {
    res.json({ success: false, shift: null });
  }
});

app.post('/api/shifts/close', async (req, res) => {
  try {
    const { shift_id, actual_cash } = req.body;
    const result = await client.query(
      'UPDATE cashier_shifts SET closed_at = NOW(), expected_cash = $1, actual_cash = $2, variance = $3, status = \'closed\' WHERE id = $4 AND status = \'open\' RETURNING id',
      [actual_cash, actual_cash, 0, shift_id]
    );
    if (result.rows.length > 0) {
      res.json({ success: true, message: 'Shift closed successfully.' });
    } else {
      res.json({ success: false, message: 'Shift not found or already closed.' });
    }
  } catch (error) {
    res.json({ success: false, message: 'Failed to close shift: ' + error.message });
  }
});

app.get('/api/shifts/list', async (req, res) => {
  try {
    const { from_date, to_date, user_id } = req.query;
    let query = 'SELECT cs.*, u.full_name AS cashier_name FROM cashier_shifts cs JOIN users u ON u.id = cs.user_id WHERE 1=1';
    const params = [];

    if (from_date) { query += ' AND cs.opened_at >= $' + (params.length + 1); params.push(from_date); }
    if (to_date) { query += ' AND cs.opened_at <= $' + (params.length + 1); params.push(to_date); }
    if (user_id) { query += ' AND cs.user_id = $' + (params.length + 1); params.push(user_id); }

    query += ' ORDER BY cs.opened_at DESC';
    const result = await client.query(query, params);
    res.json({ success: true, shifts: result.rows });
  } catch (error) {
    res.json({ success: true, shifts: [] });
  }
});

startServer();
