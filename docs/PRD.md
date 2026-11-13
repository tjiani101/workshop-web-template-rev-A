# Product requirements

## Product goal

Give riders a clear path from understanding a service to booking it, then give staff a reliable record of the work, inspection, customer decisions, parts, and payment.

## People and access

- **Guest:** browse published services and packages, contact the workshop, and begin registration.
- **Customer:** manage their own vehicles and bookings, review inspections, approve or decline recommended work, and see payments.
- **Mechanic:** view assigned work, perform checklists, record findings and media, and recommend additional work.
- **Administrator / owner:** manage content, catalog, availability, bookings, users, stock, and finances according to assigned permissions.

## Public experience

Homepage, service catalog and detail, packages, pricing, about, and contact pages. Catalog and published content must come from persisted records. Motorcycle photography, graphite/titanium/off-white surfaces, restrained red accents, responsive layouts, and direct language establish the visual system. Do not invent business contact details, verified compatibility, prices, certifications, or customer reviews.

## Operating capabilities

The target product includes dynamic service categories, a configurable package builder, OEM and aftermarket part selection with technical variants, customer accounts and vehicles, booking and scheduling, admin and mechanic workspaces, inspections, customer decisions on additional work, inventory reservations and consumption, payments and audit history.

## Data integrity rules

- Recalculate prices on the server from active catalog data.
- Store monetary amounts as integer Rupiah or DECIMAL, never float.
- Snapshot names, variants, quantities, prices, discounts, durations, and customer decisions on transactions.
- Disabling a category hides it from new public bookings but does not delete history.
- Enforce customer ownership and mechanic assignment in policies, not just UI controls.

## Delivery sequence

0. Environment, architecture, database design, and foundation.
1. Authentication, roles, design system, public site.
2. Catalog, package administration, pricing, and products.
3. Part configurator and compatibility.
4. Customer portal and booking.
5. Admin scheduling and assignment.
6. Mechanic work orders and inspections.
7. Additional-work approvals.
8. Inventory and payments.
9. Security review, tests, and deployment readiness.

Each module is documented in its workflow guide and released in sequence.
