# Graph Report - krl-app  (2026-10-04)

## Corpus Check
- 2 files · ~54,106 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1254 nodes · 3204 edges · 75 communities (41 shown, 34 thin omitted)
- Extraction: 99% EXTRACTED · 1% INFERRED · 0% AMBIGUOUS · INFERRED: 19 edges (avg confidence: 0.81)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Database Migrations
- Domain Models & Relations
- Docs & Docker Services
- Composer Dependencies
- Public Pages & Station Search
- API Resources & App Bootstrap
- Frontend Auth Providers & Header
- Frontend Packages & Lint
- Schedule & Watch Feature Tests
- Sync Services & Sync Log
- Admin Sync Panels
- Admin Dashboard & Stations API
- Departure Boards
- Login & Token Auth
- Auth Forms & Hydration
- Form Requests
- Station Sync Tests
- Station & Stop API Settings
- Admin Panel Pages
- Backend Vite Tooling
- Admin Sync Status UI
- Settings & Sync Logs
- Admin Pages & Error Boundary
- Train Stops Sync
- Users & Favorite Stations
- KCI URL Client
- TypeScript Config
- Schedule Controllers
- KCI Clients & Errors
- Schedule Search & Sync
- Admin Stats & Schedule Model
- User Model & Admin Tests
- Admin Shell & Toasts
- Schedule Board
- Frontend API Client
- Schedule API Settings
- KCI DTOs & Service
- Admin User Management
- Queued Sync Jobs
- HTTP KCI Client
- Auth Tests
- Sync Status Enum
- KCI Service Fetching
- KCI Timetable Watch
- Mock KCI Client
- User Factory
- Unit Tests
- Roles & Seeding
- Admin Middleware
- Schedules API Tests
- User Eloquent Base
- Train Stop Reachability
- E2E Flow Script
- Station Sync & Watch Commands
- Root Layout & Toasts
- Timetable Watch Tests
- KCI Rate Limit Tests
- User Policy
- Console Schedule
- Admin Guard Concepts
- Docker Entrypoint
- PostCSS Config

## God Nodes (most connected - your core abstractions)
1. `Station` - 83 edges
2. `User` - 73 edges
3. `SyncLog` - 51 edges
4. `Schedule` - 49 edges
5. `Setting` - 46 edges
6. `Card()` - 42 edges
7. `ScheduleSyncService` - 41 edges
8. `errorMessage()` - 32 edges
9. `react` - 32 edges
10. `StationSyncService` - 30 edges

## Surprising Connections (you probably didn't know these)
- `robots.txt allow-all policy` --conceptually_related_to--> `Laravel 13 Backend (REST API /api/v1)`  [INFERRED]
  backend/public/robots.txt → README.md
- `Laravel Framework README (boilerplate)` --conceptually_related_to--> `Laravel 13 Backend (REST API /api/v1)`  [INFERRED]
  backend/README.md → README.md
- `Next.js agent rules (read node_modules/next/dist/docs)` --conceptually_related_to--> `Next.js 16 Frontend (public site + /admin)`  [INFERRED]
  frontend/AGENTS.md → README.md
- `create-next-app README (boilerplate)` --conceptually_related_to--> `Next.js 16 Frontend (public site + /admin)`  [INFERRED]
  frontend/README.md → README.md
- `Props` --references--> `Station`  [EXTRACTED]
  frontend/src/components/schedule/StationCombobox.tsx → frontend/src/lib/api/types.ts

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Backend containers sharing x-backend config** — docker_compose_x_backend, docker_compose_backend, docker_compose_queue, docker_compose_scheduler [EXTRACTED 1.00]
- **KCI Sync Pipeline (ScheduleSyncService -> KciService -> KciClient impls)** — readme_schedulesyncservice, readme_kciservice, readme_kciclient, readme_httpkciclient, readme_mockkciclient [EXTRACTED 1.00]
- **Web & admin authentication flow** — readme_sanctum_spa_cookie_auth, frontend_src_lib_api_client, readme_authprovider, readme_adminauthprovider, readme_admin_session_guard, readme_ensureuserisadmin [INFERRED 0.85]

## Communities (75 total, 34 thin omitted)

### Community 0 - "Database Migrations"
Cohesion: 0.05
Nodes (21): {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}(), {closure#3}() (+13 more)

### Community 1 - "Domain Models & Relations"
Cohesion: 0.06
Nodes (22): {closure#1}(), {closure#2}(), {closure#1}(), {closure#1}(), {closure#10}(), {closure#13}(), {closure#14}(), {closure#15}() (+14 more)

### Community 2 - "Docs & Docker Services"
Cohesion: 0.05
Nodes (46): Laravel Boost Guidelines (agent bootstrap), Laravel Boost Guidelines (CLAUDE.md copy), robots.txt allow-all policy, Laravel Boost, Laravel Framework README (boilerplate), backend service (serve), frontend service (next dev), pgdata volume (+38 more)

### Community 3 - "Composer Dependencies"
Cohesion: 0.04
Nodes (48): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+40 more)

### Community 4 - "Public Pages & Station Search"
Cohesion: 0.09
Nodes (33): nextConfig, HomePage(), POPULAR, metadata, RegisterPage(), metadata, SchedulePage(), metadata (+25 more)

### Community 5 - "API Resources & App Bootstrap"
Cohesion: 0.08
Nodes (12): TrainLineResource, AppServiceProvider, {closure#3}(), {closure#4}(), {closure#5}(), {closure#1}(), {closure#2}(), {closure#3}() (+4 more)

### Community 6 - "Frontend Auth Providers & Header"
Cohesion: 0.10
Nodes (29): AdminRootLayout(), metadata, PublicLayout(), ClockIcon(), HomeIcon(), Icon(), isActive(), Logo() (+21 more)

### Community 7 - "Frontend Packages & Lint"
Cohesion: 0.06
Nodes (31): eslintConfig, dependencies, next, react, react-dom, devDependencies, eslint, eslint-config-next (+23 more)

### Community 8 - "Schedule & Watch Feature Tests"
Cohesion: 0.09
Nodes (4): TrainLine, FavoriteStationsTest, ScheduleApiTest, TestCase

### Community 9 - "Sync Services & Sync Log"
Cohesion: 0.08
Nodes (7): FinishesSyncLog, {closure#1}(), {closure#10}(), {closure#6}(), {closure#7}(), {closure#2}(), {closure#5}()

### Community 10 - "Admin Sync Panels"
Cohesion: 0.12
Nodes (27): ApiSyncPanel(), UrlForm(), ScheduleSyncPanel(), StationSyncPanel(), AdminStationsMeta, getSchedulesApiSetting(), getStationsApiSetting(), getTrainStopsApiSetting() (+19 more)

### Community 11 - "Admin Dashboard & Stations API"
Cohesion: 0.11
Nodes (7): DashboardController, StationController, {closure#2}(), SyncController, Controller, SyncLogResource, AdminStationService

### Community 12 - "Departure Boards"
Cohesion: 0.19
Nodes (22): DepartureRow(), FavoriteDepartures(), StationCard(), UpcomingDepartures(), LineDot(), getFavoriteStations(), getNextDepartures(), getUpcomingDepartures() (+14 more)

### Community 13 - "Login & Token Auth"
Cohesion: 0.11
Nodes (5): AuthController, TokenController, TokenRequest, AuthService, {closure#1}()

### Community 14 - "Auth Forms & Hydration"
Cohesion: 0.19
Nodes (17): AdminLoginPage(), LoginPage(), metadata, AuthCard(), LoginForm(), RegisterForm(), safeNext(), useRedirectIfAuthenticated() (+9 more)

### Community 15 - "Form Requests"
Cohesion: 0.10
Nodes (4): AuthController, UpdateStationRequest, LoginRequest, RegisterRequest

### Community 16 - "Station Sync Tests"
Cohesion: 0.11
Nodes (5): Station, {closure#3}(), {closure#1}(), StationSyncTest, FakeKciClient

### Community 17 - "Station & Stop API Settings"
Cohesion: 0.18
Nodes (3): StationsApiController, TrainStopsApiController, StationSyncService

### Community 18 - "Admin Panel Pages"
Cohesion: 0.33
Nodes (18): AdminSchedulesPage(), AdminStationsPage(), AdminSyncPage(), AdminUsersPage(), EmptyState(), ErrorState(), PageHeader(), Pagination() (+10 more)

### Community 19 - "Backend Vite Tooling"
Cohesion: 0.10
Nodes (20): devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite, tailwindcss, optionalDependencies (+12 more)

### Community 20 - "Admin Sync Status UI"
Cohesion: 0.16
Nodes (17): ApiPreview, ApiUrlSetting, Props, ScheduleSyncStatus(), LABELS, SYNC_TYPE_LABELS, SyncStatusBadge(), TRIGGER_LABELS (+9 more)

### Community 21 - "Settings & Sync Logs"
Cohesion: 0.16
Nodes (4): Setting, SyncLog, {closure#2}(), StationsApiTest

### Community 22 - "Admin Pages & Error Boundary"
Cohesion: 0.24
Nodes (17): DashboardPage(), Item(), Stat(), AdminStationDetailPage(), BackLink(), CoordinatesForm(), Row(), AccountPage() (+9 more)

### Community 23 - "Train Stops Sync"
Cohesion: 0.14
Nodes (4): {closure#2}(), TrainStop, {closure#5}(), TrainStopSyncService

### Community 24 - "Users & Favorite Stations"
Cohesion: 0.17
Nodes (5): FavoriteStationController, StationController, UpdateFavoriteStationsRequest, StationResource, FavoriteStationService

### Community 25 - "KCI URL Client"
Cohesion: 0.16
Nodes (3): {closure#2}(), {closure#3}(), KciUrlClient

### Community 26 - "TypeScript Config"
Cohesion: 0.11
Nodes (18): compilerOptions, allowJs, esModuleInterop, incremental, isolatedModules, jsx, lib, module (+10 more)

### Community 27 - "Schedule Controllers"
Cohesion: 0.18
Nodes (6): ScheduleController, {closure#1}(), {closure#2}(), ScheduleController, ScheduleFilterRequest, ScheduleResource

### Community 30 - "Admin Stats & Schedule Model"
Cohesion: 0.22
Nodes (3): Schedule, AdminStatsService, KciSyncTest

### Community 32 - "Admin Shell & Toasts"
Cohesion: 0.20
Nodes (14): AdminPanelLayout(), NAV, AdminUserDetailPage(), Row(), Spinner(), STYLES, Toast, ToastContext (+6 more)

### Community 33 - "Schedule Board"
Cohesion: 0.21
Nodes (15): ArrivalText(), BoardSkeleton(), Chip(), NextBadge(), Props, ScheduleBoard(), TripInfo(), normalize() (+7 more)

### Community 34 - "Frontend API Client"
Cohesion: 0.17
Nodes (14): adminLogout(), apiFetch(), buildUrl(), FRIENDLY_MESSAGES, friendlyMessage(), Query, readCookie(), RequestOptions (+6 more)

### Community 35 - "Schedule API Settings"
Cohesion: 0.25
Nodes (3): SyncKciSchedules, SchedulesApiController, ScheduleSyncService

### Community 36 - "KCI DTOs & Service"
Cohesion: 0.16
Nodes (7): KciSchedule, KciStation, {closure#1}(), {closure#2}(), {closure#4}(), {closure#1}(), {closure#1}()

### Community 37 - "Admin User Management"
Cohesion: 0.20
Nodes (3): UserController, UpdateUserRoleRequest, UserResource

### Community 42 - "Sync Status Enum"
Cohesion: 0.22
Nodes (6): SyncStatus, Failed, Partial, Queued, Running, Success

### Community 44 - "KCI Timetable Watch"
Cohesion: 0.36
Nodes (3): KciTimetableCheck, {closure#2}(), KciTimetableWatchService

### Community 47 - "Unit Tests"
Cohesion: 0.31
Nodes (3): ExampleTest, {closure#1}(), SyncStatusContractTest

### Community 48 - "Roles & Seeding"
Cohesion: 0.28
Nodes (4): UserRole, Admin, User, DatabaseSeeder

### Community 55 - "Root Layout & Toasts"
Cohesion: 0.33
Nodes (5): jakarta, metadata, RootLayout(), viewport, ToastProvider()

### Community 62 - "Admin Guard Concepts"
Cohesion: 0.50
Nodes (3): AdminAuthProvider / useAdminAuth, EnsureUserIsAdmin middleware, users table

## Knowledge Gaps
- **164 isolated node(s):** `Props`, `PaginationMeta`, `SyncType`, `TrainLine`, `SchedulesApiPreview` (+159 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 370 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **34 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Station` connect `Station Sync Tests` to `Domain Models & Relations`, `Schedule & Watch Feature Tests`, `Sync Services & Sync Log`, `Auth Tests`, `Admin Dashboard & Stations API`, `Sync Status Enum`, `Form Requests`, `Roles & Seeding`, `Station & Stop API Settings`, `Schedules API Tests`, `Settings & Sync Logs`, `Train Stops Sync`, `Users & Favorite Stations`, `Timetable Watch Tests`, `Schedule Controllers`, `Schedule Search & Sync`, `Admin Stats & Schedule Model`, `User Model & Admin Tests`?**
  _High betweenness centrality (0.054) - this node is a cross-community bridge._
- **Why does `User` connect `User Model & Admin Tests` to `Domain Models & Relations`, `Schedule & Watch Feature Tests`, `Sync Services & Sync Log`, `Login & Token Auth`, `Station Sync Tests`, `Station & Stop API Settings`, `Settings & Sync Logs`, `Train Stops Sync`, `Admin Stats & Schedule Model`, `Schedule API Settings`, `Admin User Management`, `Auth Tests`, `Sync Status Enum`, `User Factory`, `Roles & Seeding`, `Schedules API Tests`, `User Eloquent Base`, `Train Stop Reachability`, `Timetable Watch Tests`, `User Policy`, `Console Schedule`?**
  _High betweenness centrality (0.035) - this node is a cross-community bridge._
- **Why does `Sanctum SPA Cookie Authentication` connect `Docs & Docker Services` to `Frontend API Client`?**
  _High betweenness centrality (0.024) - this node is a cross-community bridge._
- **What connects `Props`, `PaginationMeta`, `SyncType` to the rest of the system?**
  _164 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Database Migrations` be split into smaller, more focused modules?**
  _Cohesion score 0.052214452214452214 - nodes in this community are weakly interconnected._
- **Should `Domain Models & Relations` be split into smaller, more focused modules?**
  _Cohesion score 0.06095791001451379 - nodes in this community are weakly interconnected._
- **Should `Docs & Docker Services` be split into smaller, more focused modules?**
  _Cohesion score 0.050170068027210885 - nodes in this community are weakly interconnected._