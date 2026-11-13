# MINEX project overview

MINEX is a motorcycle maintenance and workshop operations platform for everyday riders. It combines a public service catalog with customer booking, workshop execution, inspection approvals, parts inventory, and payment records.

## Current implementation

The application covers the core request-to-handover lifecycle:

- Public motorcycle service, package, parts, pricing, about, and contact pages backed by editable catalog/content records.
- Booking-time estimate preview for configured catalog prices, with unpriced items called out for workshop confirmation; live candidate appointment times for the requested work.
- Customer registration, garage management, booking history, private inspection reports, additional-work decisions, and payment history.
- Admin catalog and vehicle/fitment management, booking review, capacity-aware appointment assignment, mechanic profiles, inventory, manual payment verification, refunds, expense ledger, and editable customer records.
- Mechanic-only assigned work orders, check-in, checklist, inspection findings/media, recommendations with fitment confirmation, actual part consumption, and handover controls.
- Internal notifications and audit records for key operational changes.

The app should not be treated as production-ready until MINEX supplies real pricing, fitment data, capacity/hours, contact details, and payment/refund rules. Outbound email/WhatsApp and payment-gateway adapters are not configured. See [PRD](PRD.md), [DECISIONS](DECISIONS.md), and [deployment](DEPLOYMENT.md).

Customers can maintain their own contact details and administrators can update customer contact information, internal notes, and membership access; sensitive profile changes are audited. Customer testimonials are intentionally absent until MINEX has real approved quotes. Gallery photos can be replaced through website content settings; richer site-media workflows, external notification delivery, payment gateway integration, and purchase orders remain future work.

## Runtime

- PHP 8.3+
- Laravel 13, Blade, Eloquent, and server rendered forms
- MariaDB 10.11 for the local environment; MySQL-compatible schema
- Vanilla JavaScript and custom CSS under `public/`; Node is not required to serve the production app. The optional Vite pipeline can be run with `npm ci && npm run build`.
- Rupiah amounts stored as integer minor units; no binary floating point for money

## Local setup

See [deployment notes](DEPLOYMENT.md). Credentials belong only in `.env`, which is ignored by Git. Do not use the local development database account in production.
