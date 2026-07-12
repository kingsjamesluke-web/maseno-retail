# Render Deployment Guide

## Prerequisites
- GitHub repository with your code pushed
- Render account (sign up at https://render.com)
- Neon database URL ready

---

## Step-by-Step Deployment

### Step 1: Push Code to GitHub
```bash
cd /home/the-billonare/Desktop/maseno_retail
git init
git add .
git commit -m "Initial commit - Maseno Retail ERP with Neon database"
git remote add origin https://github.com/YOUR_USERNAME/maseno-retail.git
git branch -M main
git push -u origin main
```

### Step 2: Create Blueprint on Render
1. Go to https://dashboard.render.com
2. Click **"New +"** button (top right)
3. Select **"Blueprint"**
4. Click **"Connect a repository"**
5. Authorize Render to access your GitHub account
6. Select your `maseno-retail` repository
7. Render will automatically detect `render.yaml` in your repo root

### Step 3: Configure Environment Variables

**Important:** The `render.yaml` has placeholders marked `sync: false` for security. You must set these manually.

#### For Frontend Service (maseno-retail-frontend):
Click on the frontend service after initial deploy, then go to **Environment** tab:

Add these environment variables:
- **Key:** `DATABASE_URL` | **Value:** Your Neon database URL
  ```
  postgresql://neondb_owner:npg_ZavUNJH0q9KL@ep-little-wind-att0778r-pooler.c-9.us-east-1.aws.neon.tech/neondb?sslmode=require&channel_binding=require
  ```
- **Key:** `BACKEND_URL` | **Value:** Leave blank initially, or set to your backend URL if you deploy it first
  ```
  (leave empty for same-origin, or set to: https://maseno-retail-backend.onrender.com)
  ```
- **Key:** `PHP_DISPLAY_ERRORS` | **Value:** `1`
- **Key:** `PHP_MEMORY_LIMIT` | **Value:** `256M`

#### For Backend Service (maseno-retail-backend):
Click on the backend service, then go to **Environment** tab:

Add these environment variables:
- **Key:** `DATABASE_URL` | **Value:** Same as frontend (your Neon database URL)
  ```
  postgresql://neondb_owner:npg_ZavUNJH0q9KL@ep-little-wind-att0778r-pooler.c-9.us-east-1.aws.neon.tech/neondb?sslmode=require&channel_binding=require
  ```
- **Key:** `PORT` | **Value:** `3000` (already in render.yaml)
- **Key:** `NODE_ENV` | **Value:** `production` (already in render.yaml)

### Step 4: Deploy and Test
1. After setting environment variables, Render will auto-redeploy
2. Wait for both services to show **"Live"** status (green checkmark)
3. Your frontend will be available at: `https://maseno-retail-frontend.onrender.com`
4. Your backend will be available at: `https://maseno-retail-backend.onrender.com`
5. Test the application by visiting the frontend URL

---

## Architecture Overview

```
┌─────────────────────────────────────────┐
│         Render Cloud Platform          │
├─────────────────────────────────────────┤
│                                         │
│  ┌──────────────────────────────────┐  │
│  │  Frontend PHP Web Service       │  │
│  │  - Port: 10000                  │  │
│  │  - Serves: HTML, CSS, JS        │  │
│  │  - URL: your-app.onrender.com   │  │
│  │  - DB: Neon PostgreSQL           │  │
│  └──────────────────────────────────┘  │
│          ↕ (same origin or CORS)       │
│  ┌──────────────────────────────────┐  │
│  │  Backend Node.js Web Service     │  │
│  │  - Port: 3000                   │  │
│  │  - Purpose: API bridge         │  │
│  │  - DB: Neon PostgreSQL           │  │
│  └──────────────────────────────────┘  │
│                                         │
│  ┌──────────────────────────────────┐  │
│  │  Neon Database (PostgreSQL)     │  │
│  │  - Connection: DATABASE_URL     │  │
│  └──────────────────────────────────┘  │
└─────────────────────────────────────────┘
```

---

## Current Frontend URL Handling

The JavaScript files (`js/pos.js`, `js/app.js`) use relative URLs:
- `api/products.php`
- `api/cart.php`
- `api/customers.php`

These resolve to the same origin (frontend domain), so they work without modification.

The `BACKEND_URL` constant is available in the JavaScript for future Node.js API calls:
```javascript
const BACKEND_URL = ''; // or your backend URL in production
// Usage: fetch(BACKEND_URL + '/api/some-endpoint')
```

---

## Troubleshooting

### Database Connection Errors
- **Verify DATABASE_URL** is set correctly in both services
- **Check SSL mode**: Your Neon URL uses `?sslmode=require` (correct for Render)
- **Neon connection limits**: Free tier has connection limits; consider using a connection pool

### PHP Service Won't Start
- Ensure `startCommand` is: `php -S 0.0.0.0:10000`
- Check logs in Render dashboard under **Events** tab

### Node.js Service Won't Start
- Ensure `npm install` completes successfully
- Check `server.js` logs for database connection errors
- Verify `DATABASE_URL` is set in backend service

### Cross-Origin Requests (CORS)
The Node.js server has CORS enabled (`Access-Control-Allow-Origin: *`).
If you set `BACKEND_URL` in production, you may want to restrict CORS origins.

### Static Files Not Loading
- Ensure CSS/JS paths use relative paths: `css/style.css`, `js/pos.js`
- Check that `server.php` router is being used correctly

---

## Additional Notes

1. **Database Schema**: Ensure your `sql/schema.sql` has been executed on the Neon database before testing
2. **File Uploads**: Render's filesystem is ephemeral; for production file uploads (images, documents), use:
   - Render Disk Storage (if available on your plan)
   - External storage like Cloudinary, AWS S3, or Supabase Storage
3. **PHP Sessions**: Sessions should work on Render's infrastructure
4. **Custom Domain**: You can add a custom domain in Render's dashboard under **Settings** → **Custom Domains**
5. **HTTPS**: Render provides automatic HTTPS certificates

---

## Quick Reference

**Frontend URL:** `https://maseno-retail-frontend.onrender.com`  
**Backend URL:** `https://maseno-retail-backend.onrender.com`  
**Database:** Neon PostgreSQL (managed externally)

**Environment Variables Required:**
- `DATABASE_URL` (both services)
- `BACKEND_URL` (frontend, optional)
- `PHP_DISPLAY_ERRORS` (frontend)
- `PHP_MEMORY_LIMIT` (frontend)
- `PORT` (backend, default 3000)
- `NODE_ENV` (backend, production)

---

## Next Steps After Deployment

1. Test all major features:
   - Dashboard loads
   - POS cart operations
   - Inventory management
   - Customer operations
   - M-Pesa integration

2. Monitor logs in Render dashboard for errors

3. Set up alerts for:
   - Service downtime
   - Error rate spikes
   - Database connection issues

4. Configure custom domain for production use

5. Set up automated backups for the Neon database (free tier doesn't include automatic backups)

6. Review Render's free tier limits:
   - Web services may sleep after inactivity
   - Consider upgrading for 24/7 availability