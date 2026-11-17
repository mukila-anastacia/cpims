# CPIMS: Centralised Procurement Information Management System

A web application for A-Solar Power Works. It takes a purchase from request to approval, quotation, order, delivery and invoice matching across nine branches, with budgets, reports and an audit trail.

Built with Laravel (PHP), React, Bootstrap and MySQL.

## Requirements

PHP 8.3 or later, Composer, Node 20 or later, MySQL 8.

## Setup

```
composer install
npm install
copy .env.example .env
php artisan key:generate
```

Create an empty MySQL database, then set `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in `.env`.

```
php artisan migrate --seed
npm run dev
php artisan serve
```

Open http://127.0.0.1:8000.

## Roles

Requester, Branch Manager, Procurement Administrator, Finance Officer, Head Office Manager, System Administrator.

In development, `php artisan migrate --seed` also creates demo accounts, vendors, items and budgets (see `database/seeders/DemoDataSeeder.php`).

## Tests

```
php artisan test
```

The tests run on a temporary in-memory database and stop if they would touch the real one.

## Going live

1. Set `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_URL`, `SESSION_SECURE_COOKIE=true` and `SESSION_COOKIE=cpims_session` in `.env`.
2. Seed only the reference data: `php artisan migrate --force --seed`. Demo data is skipped in production.
3. Create the first administrator: `php artisan cpims:create-admin`.
4. Change the approval limits and settings to A-Solar's real values (the `approval_rules` and `settings` tables).
5. Build the front end with `npm run build`, serve it over HTTPS, and set `expose_php = Off` in `php.ini`.
6. Schedule a daily database backup to storage away from the server, and test a restore.
7. Have A-Solar fill in and approve the privacy and retention wording before staff use it.