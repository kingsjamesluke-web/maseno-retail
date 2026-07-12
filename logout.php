<?php
/**
 * Maseno Retail ERP - Logout
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/auth.php';

logout_user();
redirect('login.php', 'You have been logged out.', 'info');