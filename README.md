# Nexus Campus Portal — Event Management System

Nexus Campus Portal is a modern, secure, and robust web-based Event Management System built on PHP and MySQL. Designed for universities and colleges, it supports student registrations, admin workflows, digital check-in/check-out via QR codes, feedback gathering, automated certificate issuance, and reports generation.

## 🚀 Key Features

*   **User Authentication & Security:** Signup and Login system featuring real-time email-based OTP verification using Google SMTP.
*   **Encrypted Student Data:** Crucial student fields are encrypted using AES-256-CBC locally before being written to the database.
*   **Event Portal:** Clean layout where students can browse upcoming events, check fees, and register.
*   **Admin Dashboard:** Comprehensive controls to approve/reject registrations, generate reports, seed events, and publish certificates.
*   **QR Scanner (Check-in/Check-out):** In-browser QR code scanner for event staff (Helpers) to record entry/exit times instantly.
*   **Automated Email Reports & Receipts:** Generates Excel/PDF lists of registrants and sends emails automatically.
*   **Digital Certificates:** Automatically generates dynamic PDF participation certificates featuring secure verification QR codes.

---

## 📁 Repository Structure

```text
event/
├── css/                     # Styling assets
├── js/                      # Frontend JavaScript files
├── images/                  # Event banner images and static assets
├── uploads/                 # Dynamically generated assets (Gitignored)
│   ├── certificates/        # Generated PDF participation certificates
│   ├── payment_receipts/    # Screenshots of payment receipts uploaded by students
│   ├── qrcodes/             # Saved feedback/verification QR codes
│   ├── reports/             # Generated excel and pdf registration reports
│   └── templates/           # Custom certificate templates
├── config.example.php       # Template configuration file (Database, SMTP, Encryption)
├── db_connect.php           # Database initialization and schema auto-migration
├── security.php             # Cryptographic security helper (AES-256 & CSRF)
├── otp_handler.php          # Mailer handler for OTP, checkouts, and system emails
├── send_email_async.php     # Background process script to dispatch emails asynchronously
├── schema.sql               # Database backup structure
└── [Pages]                  # index.php, login.php, register.php, dashboard.php, admin_dashboard.php, etc.
```

---

## 🛠️ Local Setup Instructions

Follow these steps to set up the project on your local machine:

### 1. Prerequisites
Ensure you have the following installed on your machine:
*   [XAMPP](https://www.apachefriends.org/) (with PHP 8.0+ and MySQL/MariaDB)
*   [Composer](https://getcomposer.org/) (for managing dependencies)

### 2. Clone the Repository
Clone the project directory to your XAMPP `htdocs` folder:
```bash
cd C:\xampp\htdocs
git clone https://github.com/YOUR_GITHUB_USERNAME/event.git
cd event
```

### 3. Install Dependencies
Install the required PHP packages (PHPMailer, Dompdf, PHP QRCode) using Composer:
```bash
composer install
```
*(Note: The `vendor/` folder is ignored in Git and will be created locally after running this command).*

### 4. Configure Environment Secrets
Copy the configuration template to create your local config file:
```bash
cp config.example.php config.php
```
Open `config.php` in your text editor and update the constants:
*   **Database Settings:** Specify your local DB host, user, and password.
*   **SMTP Configuration:** Set up your Gmail SMTP credentials. You must generate a [Google App Password](https://support.google.com/accounts/answer/185833) to send real emails.
*   **Encryption Key:** Set a unique 32-character random key for `AES_SECRET_KEY` to secure database fields.

> [!WARNING]
> Never commit `config.php` to version control. It is already added to `.gitignore`.

### 5. Initialize the Database
The system features **automatic database setup and migrations**. Simply start Apache & MySQL in the XAMPP Control Panel, then navigate to the portal in your browser:
*   `http://localhost/event/index.php`

On first load, the system will automatically create the database `event_db`, create all tables, run migrations, and seed default events/admin accounts. Alternatively, you can import `schema.sql` manually.

### 6. Admin Credentials
To log in as administrator and manage the application:
*   **Email:** `rpramanick457@gmail.com`
*   **Password:** `admin123`

---

## 🛡️ License
Distributed under the MIT License. See `LICENSE` for more information.
