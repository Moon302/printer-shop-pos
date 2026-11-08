# Printer Shop POS

A simple, fast, locally hosted Point of Sale system for a printer shop in
Pakistan. It sells printers, spare parts, ink and toner, and also charges for
services such as toner refilling and repairs.

Built with **PHP + MySQL/MariaDB + HTML + CSS + vanilla JavaScript** for
**XAMPP on Windows**. No frameworks, no build tools, no Node.js.

The main goal is **fast billing and receipt printing**.

---

## Features

- Single, focused POS screen with instant product/service search and a live cart.
- Products with auto-generated SKU and EAN-13 barcode, plus a printable barcode label.
- Products track cost price, selling price and stock.
- Services with an optional standard price (blank or `0` = price entered per job).
- Fixed (PKR) and percentage discounts, editable later.
- Thermal receipt printing at **58 mm** and **80 mm** widths.
- Bill history with search by bill number and date, discount editing, and reprinting.
- A simple date-filtered sales report.
- Simple payment handling (amount received, change, balance due, part-paid).
- One-click database backup and restore (with an automatic safety backup).
- One secure admin account, hashed passwords, CSRF protection, prepared PDO statements.

---

## Requirements

- [XAMPP](https://www.apachefriends.org/) (Apache + MySQL/MariaDB + PHP 8+).
- The project folder must be `D:\xampp\htdocs\printer-pos`
  (so it opens at `http://localhost/printer-pos/`).

---

## Folder structure

```text
printer-pos/
├── login.php          # sign-in page
├── index.php          # main POS screen (new bill)
├── logout.php         # ends the session
├── setup.php          # one-time first-admin creation
├── config.php         # database credentials + app constants
├── db.php             # shared PDO connection
├── products.php       # product management (+ barcode labels)
├── services.php       # service management
├── bills.php          # bill history
├── bill-view.php      # view / edit discount / print receipt
├── barcode.php        # printable barcode label for one product
├── reports.php        # date-filtered sales report
├── settings.php       # shop + receipt settings, backup & restore
├── assets/
│   ├── style.css
│   └── script.js
├── includes/
│   ├── header.php
│   ├── footer.php
│   └── functions.php  # shared helpers + billing logic
├── database/
│   └── database.sql   # schema + default settings
└── README.md
```

---

## Installation

### 1. Start Apache and MySQL
Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.

### 2. Configure the database credentials
Open `config.php` and set your MySQL user and password:

```php
define('DB_USER', 'root');   // your MySQL user
define('DB_PASS', '');       // your MySQL password (blank is NOT assumed — change it)
```

The database name defaults to `printer_pos`.

> **Important:** never leave `DB_PASS` blank if your MySQL root account has a
> password. If your XAMPP MySQL root still has no password, change it in
> phpMyAdmin for security.

### 3. Create the database and import the schema
1. Open `http://localhost/phpmyadmin/`.
2. Go to **Import**.
3. Choose `database/database.sql` from the `database/` folder and click **Go**.

This creates a database named `printer_pos` with all tables and default
settings. (You can also create the database manually first — the script creates
it if missing.)

### 4. Create the first admin account
Open `http://localhost/printer-pos/setup.php` and create the admin account.

There is **no default password and no public registration**. The setup page
only works while the `users` table is empty; afterwards it redirects to login.

### 5. Log in
Open `http://localhost/printer-pos/login.php` and sign in.

### 6. Add your services and products
- **Services** (`Services` in the top menu): add e.g. *Toner Refilling* (PKR 800),
  *Drum Replacement* (PKR 500), *Cleaning Service* (PKR 200). Leave the price
  blank for jobs priced per customer.
- **Products** (`Products`): add printers, ink, toner and parts with their
  selling price and stock quantity.

### 7. Test a bill and print a receipt
1. Go to **New Bill**.
2. Type to search, then click an item (or press **Enter** to add the top result).
3. Adjust quantities/prices, add a discount, enter the amount received.
4. Click **Save & Print** — the receipt opens and the print dialog appears.

---

## Keyboard shortcuts (POS screen)

| Key | Action |
| --- | --- |
| `/` or `F2` | Focus the search box |
| `Enter` (in search) | Add the first matching item |
| `Ctrl + Enter` | Save & print the current bill |

---

## Editing a bill's discount

1. Open **Bills**.
2. Click **View** next to a bill.
3. Change the discount type/value and click **Update discount**.

The total, balance and payment status are recalculated; the original bill
number and line items are kept; and the change is recorded in the edit history.

## Barcode labels

When you add a product, a unique SKU (if you leave it blank) and a 13-digit
EAN-13 barcode are generated automatically. Both are shown on the **Products**
page. Click **Label** next to a product to open the label page — choose how
many labels you want in the **Labels** box, then click **Print labels**. The
labels print in a grid, five per row, filling row by row.

> These barcodes are internal codes for this shop's labels — they are not
> GS1-registered numbers.

## Variable-price services

A service with a blank price, or a price of `0`, is treated as variable-priced.
When you add it on the POS it asks for a **Price (PKR)** that must be filled in
before the bill can be saved. Fixed-price services still fill their configured
price automatically.

## Backup & Restore

Go to **Settings → Backup & Restore**.

- **Download database backup** — saves a complete `.sql` file (products,
  services, bills, bill items, settings and users) to your computer.
- **Restore backup** — choose a previously downloaded `.sql` file to overwrite
  the current database. You must tick the confirmation box, and the app takes
  an automatic **safety backup** of the current data first.

Safety backups are written to `D:\xampp\printer_pos_backups\` (one level above
the web root), so they are **never** accessible through a browser. Keep that
folder backed up yourself if you want extra copies.

> After a restore you are logged out; log back in with the restored account.
> If a restore ever goes wrong, import the newest `safety-before-restore-*.sql`
> file from the safety-backup folder to recover the previous data.

---

## Receipt printing notes

- Printing uses the normal browser print dialog (silent printing is **not**
  supported out of the box).
- Choose the paper width (58 mm or 80 mm) in **Settings** to match the roll in
  your thermal printer.
- In the print dialog, set margins to **None** and disable headers/footers for
  a clean receipt.

---

## Troubleshooting

- **"Database connection failed"** — MySQL is not running, or `config.php`
  credentials/DB name are wrong. Check the XAMPP Control Panel and `config.php`.
- **Setup page says the database is not ready** — import `database/database.sql`
  first.
- **Not enough stock message** — increase stock in Products, or enable
  "Allow overselling" in Settings.

---

## Security notes

- Passwords are stored with `password_hash()` and verified with
  `password_verify()`.
- All database-changing forms are protected against CSRF.
- All SQL uses prepared statements (PDO). Money is stored as `DECIMAL`.
- Never expose `config.php` content; it is a PHP file and cannot be read
  directly by a browser.
