# TUNEDIK Alumni Management System — cPanel Deployment Guide

## Prerequisites
- PHP 8.0+ with PDO, cURL, fileinfo, mbstring extensions
- MySQL 5.7+ / MariaDB 10.3+
- cPanel shared hosting account

---

## Step 1 — Download PHPMailer

1. Go to https://github.com/PHPMailer/PHPMailer/releases
2. Download the latest `.zip`
3. From the extracted `src/` folder, copy these **three files** into `vendor/phpmailer/`:
   - `Exception.php`
   - `PHPMailer.php`
   - `SMTP.php`

---

## Step 2 — Create MySQL Database (cPanel)

1. Open **cPanel → MySQL Databases**
2. Create a new database, e.g. `youruser_tunedik`
3. Create a new database user with a strong password
4. Add the user to the database with **All Privileges**
5. Note down: `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`

---

## Step 3 — Configure Database Connection

Edit `config/database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'youruser_tunedik');
define('DB_PASS', 'your_strong_password');
define('DB_NAME', 'youruser_tunedik');
```

---

## Step 4 — Upload Files

**Option A — File Manager:**
1. Open cPanel → File Manager → `public_html`
2. Create a folder (e.g. `alumni` or deploy to root)
3. Upload the entire `tunedik/` folder contents into it

**Option B — FTP (FileZilla):**
1. Connect with your cPanel FTP credentials
2. Upload all files to `public_html/` or a subdirectory

---

## Step 5 — Set BASE_URL (if in a subdirectory)

If deployed to a subfolder (e.g. `https://yourdomain.com/alumni/`), the
`BASE_URL` in `config/app.php` auto-detects correctly via `SCRIPT_NAME`.

If auto-detection fails, hardcode it:
```php
define('BASE_URL', 'https://yourdomain.com/alumni');
```

---

## Step 6 — Set Directory Permissions

Via File Manager or SSH:
```
assets/uploads/         → 755
assets/uploads/receipts/→ 755
assets/uploads/logo/    → 755
assets/uploads/favicon/ → 755
```

---

## Step 7 — Run the Installer

1. Visit: `https://yourdomain.com/install.php`
2. You will see:
   - ✅ Database tables created
   - ✅ Admin account created
3. **Default admin credentials:**
   - Email: `admin@tunedik.org`
   - Password: `Admin@2024`
4. **⚠️ DELETE `install.php` immediately after this step**

---

## Step 8 — First Login & Configuration

1. Go to `https://yourdomain.com/admin/`
2. Login with default credentials
3. Go to **Settings** and configure:
   - Site name, logo, favicon
   - Paystack public + secret keys (get from https://dashboard.paystack.com)
   - Bank transfer details
   - WhatsApp number
   - SMTP email settings

---

## Step 9 — Change Admin Password

1. In admin panel, click your name → **My Account / Profile**
2. Use the **Change Password** section
3. Set a strong, unique password

---

## Folder Structure

```
tunedik/
├── admin/                  ← Admin panel
│   ├── includes/
│   │   ├── admin_header.php
│   │   └── admin_footer.php
│   ├── index.php           ← Admin dashboard
│   ├── users.php           ← User management
│   ├── payments.php        ← Payment management
│   ├── memberships.php     ← Membership overview
│   └── settings.php        ← All system settings
├── assets/
│   ├── css/style.css
│   ├── js/main.js
│   └── uploads/            ← Receipts, logos, favicons
├── config/
│   ├── app.php             ← Bootstrap file
│   └── database.php        ← DB credentials (edit this)
├── financial/              ← Financial Secretary portal
│   ├── index.php
│   ├── payments.php
│   └── report.php
├── includes/               ← Shared PHP modules
│   ├── auth.php
│   ├── functions.php
│   ├── header.php
│   ├── footer.php
│   ├── mailer.php          ← PHPMailer integration
│   └── paystack.php        ← Paystack API integration
├── pages/                  ← Member-facing pages
│   ├── register.php
│   ├── login.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── payment.php
│   ├── payment_verify.php  ← Paystack callback
│   ├── payments.php
│   └── profile.php
├── sql/schema.sql          ← Full database schema
├── vendor/phpmailer/       ← PHPMailer files go here
├── index.php               ← Homepage
├── install.php             ← One-time installer (DELETE after use)
├── .htaccess               ← Security & routing
└── DEPLOYMENT.md           ← This file
```

---

## Membership ID Format

```
08/TUN/LAG/0001
│   │   │   └── Serial number per state/country (4 digits, auto-increment)
│   │   └────── Location code: first 3 letters of Nigerian state (e.g. LAG, ABJ)
│   │            OR first 3 letters of country for non-Nigerians (e.g. GHA, USA)
│   └────────── System code (fixed: TUN)
└────────────── Prefix (fixed: 08)
```

---

## User Roles

| Role                  | Access                                              |
|-----------------------|-----------------------------------------------------|
| `member`              | Register, login, pay, view dashboard                |
| `financial_secretary` | View & approve/reject offline payments, reports     |
| `admin`               | Everything + system settings, role assignment       |

Assign roles from **Admin → Users** (dropdown per user row).

---

## Security Notes

- All passwords hashed with bcrypt (cost=12)
- All DB queries use PDO prepared statements
- CSRF tokens on every form
- File uploads: MIME + extension validation, no PHP execution in uploads/
- Session fixation prevention on login
- `.htaccess` blocks directory listing and PHP execution in uploads

---

## Paystack Setup

1. Register at https://paystack.com
2. From Dashboard → Settings → API Keys & Webhooks
3. Copy **Public Key** and **Secret Key**
4. Paste into Admin → Settings → Payments
5. Set **Callback URL** in Paystack dashboard to:
   `https://yourdomain.com/pages/payment_verify.php`

---

## Gmail SMTP Setup

1. Enable 2FA on your Google account
2. Go to https://myaccount.google.com/apppasswords
3. Generate an App Password for "Mail"
4. In Admin → Settings → Email (SMTP):
   - Host: `smtp.gmail.com`
   - Port: `587`
   - Encryption: `TLS`
   - Username: `your@gmail.com`
   - Password: *the 16-character App Password*

---

## Support & Troubleshooting

- **Blank page / 500 error:** Check PHP error log in cPanel → Error Log
- **Emails not sending:** Verify SMTP credentials, check spam folder
- **Paystack not working:** Verify secret key, check curl/SSL on server
- **Uploads failing:** Check folder permissions (755) and PHP `upload_max_filesize`
