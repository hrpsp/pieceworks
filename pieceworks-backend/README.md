# PieceWorks Backend

Laravel 11 REST API for the PieceWorks piece-rate payroll system.

## Requirements
- PHP 8.2+
- Composer
- MySQL 8.0

## Setup

```bash
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

The API will be available at `http://localhost:8000`.

Health check: `GET /api/health`
