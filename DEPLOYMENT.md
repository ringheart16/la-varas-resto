# La Vara's Resto - Deployment & Mobile Guide

This document explains how to deploy **La Vara's Resto** (POS & Online Ordering System) to Railway and how to run it as a mobile app.

---

## 1. Mobile Capabilities & PWA Features

The system has been fully optimized for mobile devices (iOS Safari, Android Chrome, tablets, and smartphones):

- **Progressive Web App (PWA) Manifest (`manifest.webmanifest`)**:
  Allows users and staff to add the app directly to their home screen:
  - **iOS (iPhone/iPad)**: Open in Safari -> Tap the **Share** button -> Tap **"Add to Home Screen"**.
  - **Android**: Open in Chrome -> Tap the **three dots menu** -> Tap **"Install app"** or **"Add to Home screen"**.
  - When opened from the home screen, the app runs in **standalone fullscreen mode** without browser address bars, looking and feeling like a native mobile app.
- **Mobile POS Drawer (Cashier)**:
  - Responsive product catalog with quick search and category filters.
  - Floating bottom cart bar on mobile screens.
  - Tapping the bottom bar opens a touch-friendly **Order Sheet / Drawer** where the cashier can enter customer names, adjust items, select payment methods (Cash, GCash, Card), calculate change, and confirm payments.
  - Mobile card view for **Order History** allowing staff to process, complete, or cancel online pickup orders with a single tap.
- **Mobile Customer Portal**:
  - Touch-friendly category pills with smooth horizontal scrolling.
  - Mobile Cart Drawer showing item breakdown, 12% VAT calculation, and checkout.
  - Real-time order status tracking with automatic updates every 15 seconds.
- **Form Usability**:
  - 16px text sizes on form inputs to prevent unwanted iOS Safari zooming.
  - Safe-area insets (`env(safe-area-inset-top)` and `env(safe-area-inset-bottom)`) supporting the iPhone Dynamic Island, notch, and Android navigation bars.

---

## 2. Deploying to Railway

### Prerequisites: Railway Account Plan
Railway requires an active account plan to provision containers and databases:
1. Go to [https://railway.com/pricing](https://railway.com/pricing).
2. Choose the **Hobby Plan** ($5/mo) or activate your account with a payment method.
*(If your account shows "Your trial has expired. Please select a plan to continue using Railway", this step is required by Railway before it allows new deployments).*

---

### Method A: Deploy via GitHub & Railway Web Dashboard (Recommended)

1. **Initialize Git and Push to GitHub**:
   ```bash
   cd C:\Users\ringt\Downloads\la_varas_system\la_varas_system
   git init
   git add .
   git commit -m "Initial commit of La Vara's Resto with mobile support"
   # Create a repository on github.com, then:
   git remote add origin https://github.com/<your-username>/<your-repo-name>.git
   git branch -M main
   git push -u origin main
   ```

2. **Create Project on Railway**:
   - Go to [https://railway.com/new](https://railway.com/new).
   - Select **Deploy from GitHub repo** and pick your repository.

3. **Add MySQL Database**:
   - In your Railway project canvas, click **+ Create / New**.
   - Select **Database** -> **Add MySQL**.

4. **Connect Web Service to MySQL**:
   - Click your Web Service -> Go to the **Variables** tab.
   - Click **New Variable** -> Add:
     - `MYSQL_URL` with value `${{MySQL.MYSQL_URL}}`
     *(Railway will automatically route internal database connections through its private network).*

5. **Generate Public Domain**:
   - In your Web Service -> Go to the **Settings** tab.
   - Scroll to **Networking** -> Click **Generate Domain**.
   - Railway will provide a URL like `https://la-varas-system-production.up.railway.app`.

---

### Method B: Deploy via Railway CLI

If you prefer deploying directly from your terminal:

```bash
cd C:\Users\ringt\Downloads\la_varas_system\la_varas_system

# 1. Verify you are logged in
railway whoami

# 2. Add MySQL database service to your project
railway add --database mysql

# 3. Add the Web service
railway add --service web

# 4. Link MySQL URL variable to web service
railway variable set MYSQL_URL="${{MySQL.MYSQL_URL}}" --service web

# 5. Upload and deploy the code
railway up --service web

# 6. Generate a public URL
railway domain --service web
```

---

## 3. How the Container Automatically Configures

- **Dockerfile**: Uses PHP 8.2 with Apache, `pdo_mysql`, `mbstring`, and Apache URL rewriting enabled.
- **Port Binding**: Railway automatically injects the `$PORT` environment variable; `docker-entrypoint.sh` automatically configures Apache to listen on that exact port.
- **Database Auto-Migration**: `init_db.php` and `bootstrap.php` run on container startup, creating all tables (`cashiers`, `customers`, `products`, `orders`, `order_items`) and seeding initial products and cashier accounts automatically.
- **Health Check**: Configured in `railway.json` pointing to `/health.php`, ensuring Railway marks the service healthy as soon as Apache boots.
