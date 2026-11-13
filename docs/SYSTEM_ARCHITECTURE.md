# System architecture

## Application shape

Laravel handles web requests, validation, sessions, role checks, database transactions, and server-rendered Blade views. Controllers adapt HTTP requests; service classes own capacity, inventory, notification, and audit operations; Eloquent models describe relations. Custom CSS and small vanilla JavaScript modules are served from `public/`.

## Repository structure

```text
app/Http/Controllers/   customer, mechanic, and admin requests
app/Http/Middleware/    role gate and active-customer gate
app/Models/             identity, catalog, scheduling, workshop, stock, finance
app/Services/           appointment availability/capacity, additional-work decisions, inventory, notifications, and audit operations
database/migrations/    relational schema
database/seeders/       roles and local-only demo records
docs/                   product, architecture, and workflow guides
public/css|js|images/   static assets
resources/views/        public pages and workspaces
routes/web.php          public, customer, mechanic, and admin routes
routes/console.php      scheduled unpaid-booking expiration
tests/Feature/          access, catalog, booking, capacity, workshop, and finance tests
```

## Data boundaries

MariaDB is the source of truth for users, vehicles, catalogs, bookings, appointments, work orders, inspections, stock, payments, refunds, expenses, settings, notifications, and audits. Booking and work-order lines keep historical snapshots so catalog edits do not rewrite old records. Inspection media, payment proof, and expense receipts use private storage; catalog imagery uses public paths. Public catalog queries filter inactive or unpublished records.

Financial writes use integer Rupiah amounts and database transactions. Browser-supplied totals are not trusted. Reservation, release, and consumption update stock levels and movement records together.

## Access control

Roles are stored as records and attached to users. Customer routes are role-gated and customer records are queried through the signed-in account. Mechanics can open a work order only when their mechanic profile is assigned to its appointment. Private media routes check the booking owner or assigned mechanic; admin-only financial records use admin role middleware. Key booking, payment, stock, refund, recommendation, and expense actions create audit records.

## Workflow

A booking stores selected catalog snapshots before staff review. The availability endpoint returns current candidate start times from selected service duration, hours, breaks, mechanic specialization/capacity, leave, existing appointments, and service-bay capacity. The selected time remains a request until an admin approves it under database locks. Payment proofs require manual verification; reaching the configured deposit confirms the appointment and opens a work order. Mechanics record check-in, findings, photos, checklist work, and server-priced recommendations. Customers decide on additional work before it can be performed. Reserved parts remain physical until their work item is marked complete. Handover requires the outstanding amount to be paid after refunds.

## External services

No payment gateway, WhatsApp, or email adapter is configured. Notifications are stored in the application for customers and mechanics. Contact form submissions are persisted for staff review. Production integrations and provider credentials are deployment decisions, not local prerequisites.
