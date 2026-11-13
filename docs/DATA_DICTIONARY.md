# Data dictionary

Money is stored as whole Rupiah integers. Quantities and measured stock balances use decimals. Workflow snapshots are retained on booking/work-order records so later catalog edits do not alter history. All tables below are implemented by migrations.

| Table(s) | Purpose / key relationships |
|---|---|
| `users`, `roles`, `permissions`, `role_user`, `permission_role` | Login identities, customer phone and active/suspended membership access, private admin notes, named access roles, permission grants, and pivots. Customer profile and membership changes are audited. |
| `service_categories`, `services`, `service_option_groups`, `service_options` | Published service catalog, labor prices/durations, configurable choices, visibility, display order, and shared operation minutes for grouped labor. |
| `packages`, `package_items` | Configurable package contents, required/optional components, prices, and duration. |
| `vehicle_categories`, `vehicle_brands`, `vehicle_models`, `customer_vehicles` | Motorcycle catalog and customer-owned garage records. |
| `part_categories`, `part_brands`, `parts`, `part_variants`, `product_attributes`, `product_attribute_values`, `part_variant_attribute` | OEM/aftermarket catalog, technical variants, prices, SKUs, and structured attributes. |
| `part_variant_vehicle_model`, `service_vehicle_model`, `package_vehicle_model` | Exact-model fitment states, source references, and verification actor/time. |
| `bookings`, `booking_items`, `booking_status_histories` | Guest/member booking requests, preferred appointment time, server-priced line snapshots, vehicle snapshots, approval/payment states, and status history. |
| `mechanic_profiles`, `mechanic_specializations`, `mechanic_leave_days` | Mechanic role details, qualified service areas, daily capacity, and leave. |
| `appointments`, `mechanic_assignments`, `appointment_histories`, `business_settings` | Reserved/confirmed schedule, mechanic workload, appointment event history, workshop hours/breaks/bay capacity, and payment deadline settings. |
| `work_orders`, `work_order_items`, `work_order_checklists`, `work_order_histories` | Work execution, item status, consumed-stock links, checklists, handover, and status history. |
| `inspections`, `inspection_findings`, `inspection_media`, `work_recommendations`, `recommendation_decision_histories` | Mechanic reports, private evidence, customer-facing recommendations, server price snapshots, fitment checks, current customer decisions, and append-only decision history. |
| `inventory_locations`, `stock_levels`, `stock_reservations`, `stock_movements`, `stock_receipts` | Physical/reserved/available balance, receiving, adjustment, reservation, release, and consumption ledger. |
| `payments`, `payment_verifications`, `refunds`, `expenses` | Submitted payments and private proof, staff decisions, refund records, and append-only expenses that can be voided with a reason. |
| `notifications`, `audit_logs` | Internal customer/mechanic notification center and security/financial event audit trail. |
| `website_content`, `contact_messages` | Editable public copy/settings and contact form inbox. |
| `cache`, `jobs`, `sessions` | Laravel runtime infrastructure tables, depending on environment configuration. |

Do not treat derived booking totals or stock availability as client inputs. Booking amounts are recomputed from catalog data; current availability is physical quantity minus reserved quantity. See [ERD](ERD.md) for the relation map.
