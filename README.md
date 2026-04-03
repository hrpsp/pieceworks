# PieceWorks

Piece-rate production tracking and payroll management for Bata Pakistan shoe manufacturing.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 11, MySQL / MariaDB, Laravel Sanctum, Laravel Queue |
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
git clone https://github.com/hrpsp/pieceworks.git
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

Run all migrations:

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

### Standard demo (DemoDataSeeder)

```bash
php artisan db:seed
```

Seeds: 3 contractors, 8 workers, rate card vv4 (36 entries), style SKUs, and a week-2026-W14 payroll run.

### Bata full demo (BataDemoSeeder — CR-009)

```bash
php artisan db:seed --class=BataDemoSeeder
```

Seeds a complete Bata Pakistan factory dataset for week **2026-W15** (Apr 6–11, 2026):

| Entity | Count |
|---|---|
| Factory location | 1 (Bata Pakistan – Lahore Factory) |
| Contractor | 1 (Khan Labour Services, TOR 15%) |
| Production lines | 4 (Cutting / Stitching / Lasting / Finishing) |
| Production units | 9 |
| Workers | 20 (grades A–D, shifts GA/E1/E2/E3) |
| Production records | 120 (20 workers × 6 days) |
| Attendance records | 120 |
| Payroll run | 1 (week 2026-W15, gross PKR 170,900) |
| Worker payrolls | 20 (with OT split columns) |
| Payroll exceptions | 3 (min-wage shortfall + compliance gap) |
| Contractor settlement | 1 (bata_owes PKR 52,615 + TOR PKR 7,892 = PKR 60,507) |
| Demo users | 4 |

**Demo credentials** are written to `pieceworks-backend/demo_credentials.md` after seeding.

### Reset Bata demo

```bash
php artisan demo:bata-reset
```

Truncates all 2026-W15 data and re-seeds from scratch.

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
php artisan queue:work
```

---

## Default Credentials

### System accounts

| Role | Email | Password |
|---|---|---|
| Admin | admin@pieceworks.local | password |
| Manager | manager@pieceworks.local | password |
| Supervisor | supervisor@pieceworks.local | password |

### Bata demo accounts (after running BataDemoSeeder)

| Role | Email | Password |
|---|---|---|
| Payroll Manager | payroll.manager@bata.demo | Password@1 |
| Supervisor | supervisor@bata.demo | Password@1 |
| Contractor Portal | contractor@bata.demo | Password@1 |
| QC Inspector | qc.inspector@bata.demo | Password@1 |

---

## Artisan Commands

| Command | Description |
|---|---|
| `php artisan payroll:calculate {weekRef}` | Trigger payroll calculation for a week |
| `php artisan payroll:sync-run-totals` | Recalculate run-level totals from worker records |
| `php artisan payroll:sync-run-totals --week=2026-W15` | Sync a specific week |
| `php artisan demo:bata-reset` | Wipe and re-seed 2026-W15 Bata demo data |
| `php artisan demo:fix-contractor-rates` | Apply canonical TOR rates to demo contractors |

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
├── pieceworks-backend/           # Laravel 11
│   ├── app/
│   │   ├── Console/Commands/
│   │   │   ├── PayrollCalculateCommand.php
│   │   │   ├── PayrollSyncRunTotalsCommand.php   # CR-006
│   │   │   ├── DemoBataResetCommand.php          # CR-010
│   │   │   └── DemoFixContractorRatesCommand.php # CR-008
│   │   ├── Http/Controllers/Api/
│   │   ├── Models/
│   │   ├── Services/
│   │   │   ├── PayrollCalculationService.php
│   │   │   └── ContractorSettlementService.php
│   │   └── Traits/ApiResponse.php
│   ├── config/
│   │   └── pieceworks.php         # Shift master config, OT thresholds, grade settings
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/
│   │       ├── DemoDataSeeder.php
│   │       ├── LeaveTypesSeeder.php  # CR-007: 15 Bata leave codes
│   │       └── BataDemoSeeder.php    # CR-009: Full Bata factory demo
│   └── routes/api.php
└── pieceworks-frontend/          # Next.js 14
    ├── app/(dashboard)/
    │   ├── payroll/
    │   ├── workers/
    │   ├── contractor/
    │   ├── rate-cards/
    │   └── reports/
    ├── components/pieceworks/
    ├── hooks/
    └── lib/
```

---

## Change Request Log

| CR | Title | Status |
|---|---|---|
| CR-001 | Multi-model wage system (daily_grade / per_pair / hybrid) | ✅ Done |
| CR-002 | Contractor CRUD + TOR field + payroll worker lines + payslips | ✅ Done |
| CR-003 | Grade system unification (A/B/C/D/trainee across all tables) | ✅ Done |
| CR-004 | Shift enum rename — morning/evening/night → GA/E1/E2/E3/GB | ✅ Done |
| CR-005 | OT config update + OT split columns + PayrollCalculationService OT logic | ✅ Done |
| CR-006 | `payroll:sync-run-totals` artisan command | ✅ Done |
| CR-007 | Leave types master table (15 Bata codes) + dispensary member flag | ✅ Done |
| CR-008 | Contractor TOR calculation in settlement service | ✅ Done |
| CR-009 | BataDemoSeeder — full 20-worker factory demo dataset | ✅ Done |
| CR-010 | `demo:bata-reset` artisan command | ✅ Done |

---

## Shift Reference

| Code | Name | Hours | Days | Night OT? |
|---|---|---|---|---|
| GA | Day shift | 07:00–17:00 (9h) | Mon–Fri | No |
| E1 | Early shift | 06:00–14:00 (8h) | Mon–Sat (Sat 5h) | No |
| E2 | Afternoon shift | 14:00–22:00 (8h) | Mon–Sat (Sat 5h) | Yes |
| E3 | Night shift | 22:00–06:00 (8h) | Mon–Sat (Sat 5h) | Yes |
| GB | Watch & Ward / Late | 17:00–03:00 (9h) | Mon–Fri | Yes |

OT thresholds: **45 h/week** (standard) · **48 h/week** (GB / Watch & Ward)

---

## Grade & Wage Reference

| Grade | Description | Min Weekly Wage |
|---|---|---|
| A | Senior / highly skilled | PKR 2,200/day |
| B | Mid-level skilled | PKR 1,800/day |
| C | Junior / semi-skilled | PKR 1,500/day |
| D | Basic / unskilled | PKR 1,200/day |
| trainee | Probationary | PKR 900/day |

Minimum weekly wage floor (statutory): **PKR 8,545**
