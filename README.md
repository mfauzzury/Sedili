# 🇰🇪 Kenyan HRM System (Open Source)

A modern, Laravel + FilamentPHP-based **Human Resource Management System** tailored for Kenyan businesses. This solution simplifies employee management, payroll (PAYE, NHIF, NSSF), attendance, and more — all in compliance with Kenyan labor laws.

<img src="img.png" alt="Open source HRM" style="max-width: 100%; border-radius: 8px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);" >

---

## 🔧 Tech Stack

-   **Laravel 12+**
-   **FilamentPHP 3.x** (admin panel)
-   **MySQL/MariaDB** (database)
-   **PHP 8.2+**
-   **Tailwind CSS** (via Filament)
-   **Alpine.js** (via Filament)

---

## 🚀 Features

### ✅ Core Modules

-   **Employee Records** (with KRA PIN, NSSF, NHIF, etc.)
-   **Departments**
-   **Attendance**
-   **Leave**
-   **Payroll**
-   **Tasks board**
-   **Employee portal** (/portal)
-   **Calendar**
-   **Kanban page**
-   **Internal messaging**
-   _More modules coming soon_  
     ( Recruitment, Training, etc.)

## ⚙️ Installation

```bash
git clone https://github.com/michaelnjuguna/open-source-hrm.git
cd open-source-hrm

composer install
cp .env.example .env
php artisan key:generate

# Setup DB credentials in .env
php artisan migrate --seed

composer run dev
```

### 🐳 Run with Docker (Laravel Sail)

Runs the app (PHP 8.3), MySQL 8.0 and Mailpit. Your normal `.env` is left as is for native use; the container reads its own `.env.docker`.

```bash
cp .env.example .env
php artisan key:generate   # or set APP_KEY in .env

# Container env: same as .env but with the Docker hostnames
sed -e 's/^DB_HOST=.*/DB_HOST=mysql/' \
    -e 's/^DB_PORT=.*/DB_PORT=3306/' \
    -e 's/^MAIL_MAILER=.*/MAIL_MAILER=smtp/' \
    -e 's/^MAIL_HOST=.*/MAIL_HOST=mailpit/' \
    -e 's/^MAIL_PORT=.*/MAIL_PORT=1025/' .env > .env.docker

export WWWUSER=$(id -u) WWWGROUP=$(id -g)
docker compose up -d --build
docker compose exec laravel.test php artisan migrate --seed
```

-   App: http://localhost
-   Mailpit (caught emails): http://localhost:8025
-   MySQL from your host: `127.0.0.1:3308` (change with `FORWARD_DB_PORT`)
-   Frontend dev server: `docker compose exec laravel.test npm run dev`

If you change `.env` (for example `APP_KEY`), mirror it in `.env.docker`.

## 🤝 Contributing

All contributions are welcome. Please fork the repo, create a feature branch and submit a pull request.

This project is actively maintained. All changes and new features are merged into the `main` branch every Friday. Contributors are encouraged to submit pull requests for review before this weekly merge.

## 📜 License

[MIT license](LICENSE)

Made with ❤️
