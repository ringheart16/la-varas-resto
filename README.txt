LA VARA'S RESTO - COMPLETE SYSTEM (POS + CUSTOMER PORTAL)

  index.html     Home page: choose Customer or Cashier
  customer.html  Customer: sign in / create account, dashboard, shop, cart, orders
  cashier.html   Cashier: sign in / create account, POS, order history, manage online orders
  api.php        Single backend used by both pages
  config.php     MySQL settings (XAMPP defaults: root, no password)
  database.sql   Database "la_varas"
  setup.php      One-click installer      check.php   Diagnostics (delete both when done)

INSTALL
1. Copy this folder to C:\xampp\htdocs\la_varas_system\
2. Start Apache + MySQL in XAMPP.
3. Open http://localhost/la_varas_system/setup.php  (creates the database)
   (or import database.sql in phpMyAdmin; an existing la_varas database is
    upgraded automatically, nothing is lost)
4. Open http://localhost/la_varas_system/ and pick Customer or Cashier.
   Internet is required for the Tailwind/React CDN scripts.

HOW IT'S CONNECTED
- One database, one API, one products table with live stock.
- Customer places an order -> appears for the cashier as "Online / Pending".
- Cashier: Process / Complete / Cancel (cancel returns stock) -> the customer's
  dashboard updates automatically (every 15 seconds).
- POS sales and online orders both deduct stock and share Order History.
- Online: 12% VAT, no delivery fee. Cash = pay on pickup; GCash/Card need a reference.
- Passwords are hashed; customer sessions are PHP sessions.

TROUBLESHOOTING
- Open check.php to see PHP version, extensions, database and tables.
- Always use http://localhost/..., never double-click the HTML files.
- If a cashier was signed in before an upgrade, sign out and in once.
