# PieceWorks

Piece-rate production tracking and payroll management for Pakistani shoe manufacturing.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 11, MySQL, Laravel Sanctum, Laravel Queue |
| Frontend | Next.js 14, TypeScript, TanStack Query, Tailwind CSS |
| Auth | Token-based (Sanctum), cookie-forwarded to Next.js middleware |

---

## Prerequisites

- PHP 8.2+ with extensions: `pdo_mysql`, `mbstring`, `bcmath`, `xml`
- Composer 2.x
- MySQL 8.0+ (or MariaDB 10.11+)
- Node.js 20+ and npm 10+

---

## Installation

### 1. Clone

```bash
git clone <repo-url> pieceworks
cd pieceworks
```

### 2. Backend

```bash
cd pieceworks-backend
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set your database credentials:

```
DB_DATABASE=pieceworks_db
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

Run migrations:

```bash
php artisan migrate
```

### 3. Frontend

```bash
cd ../pieceworks-frontend
npm install
```

`.env.local` is already configured for local development:

```
NEXT_PUBLIC_API_URL=http://localhost:8000/api
```

---

## Seeding Demo Data

From the backend directory:

```bash
php artisan db:seed --class=DemoDataSeeder
```

This seeds:

- 3 contractors, 8 workers across 2 lines
- Rate card v4.0 with 21 entries (4 tasks × 3 grades × 2 tiers)
- 4 style SKUs (BT-101, BT-204, BT-318, BT-422)
- Mon–Sat production records for week **2026-W14** (Mar 30 – Apr 4 2026)
- 1 open payroll run with 2 unresolved exceptions (min-wage shortfall + disputed records)
- Default admin user (see credentials below)

---

## Running Locally

Open two terminals:

**Terminal 1 — Backend**

```bash
cd pieceworks-backend
php artisan serve --port=8000
```

**Terminal 2 — Frontend**

```bash
cd pieceworks-frontend
npm run dev
```

App available at **http://localhost:3000**

Queue worker (optional, needed for async jobs):

```bash
cd pieceworks-backend
php artisan queue:work
```

---

## Default Credentials

| Role | Email | Password |
|---|---|---|
| Admin | admin@pieceworks.local | password |
| Manager | manager@pieceworks.local | password |
| Supervisor | supervisor@pieceworks.local | password |

---

## API Health Check

```bash
curl http://localhost:8000/api/health
```

Expected response:

```json
{
  "status": "ok",
  "version": "1.0.0",
  "app": "PieceWorks",
  "env": "local",
  "db": "connected"
}
```

---

## Key URLs

| URL | Description |
|---|---|
| `http://localhost:3000` | Frontend dashboard |
| `http://localhost:8000/api/health` | Backend health check |
| `http://localhost:8000/api/` | REST API base |

---

## Project Structure

```
pieceworks/
├── pieceworks-backend/      # Laravel 11
│   ├── app/
│   │   ├── Http/Controllers/Api/
│   │   ├── Models/
│   │   └── Traits/ApiResponse.php
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/DemoDataSeeder.php
│   └── routes/api.php
└── pieceworks-frontend/     # Next.js 14
    ├── app/
    │   ├── (auth)/login/
    │   └── (dashboard)/
    │       ├── payroll/
    │       ├── workers/
    │       ├── contractor/
    │       ├── rate-cards/
    │       └── reports/
    ├── components/pieceworks/
    ├── hooks/
    └── lib/
```
