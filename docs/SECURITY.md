# Security baseline

- Laravel hashes passwords, rotates sessions on login, invalidates sessions on logout, and applies CSRF protection to state-changing web forms.
- Login, booking submission, contact, and customer-decision routes are rate limited. Registration assigns only the customer role.
- Admin and mechanic routes use role middleware. Suspended customer accounts lose access to customer workspaces and booking actions. Customer profile and vehicle queries are account-scoped; mechanic work-order actions verify assignment. Private inspection media, payment proof, and expense receipts use private storage and authorized routes.
- Customer and admin profile changes are validated, scoped to the target customer, and written to the audit log. Membership suspension preserves booking history and does not delete the account.
- Request values are validated server-side. Catalog prices, payment balances, fitment eligibility, stock availability, and scheduling capacity are recomputed from trusted records.
- Stock reservations, payment verification, refunds, booking approval, and expense records use database transactions where state must move together. Sensitive changes write audit records.
- Production secrets stay in environment configuration and are excluded from source control. Use a dedicated production database account and serve only Laravel's `public/` directory.

The feature suite covers role access, booking price integrity, member/guest payments, manual verification, private proof/media access, customer ownership, mechanic assignment, fitment confirmation, stock reservation/consumption/release, capacity and scheduling, unpaid expiration/refund requests, and expense record/void behavior. Run `php artisan test` to execute it.

Before production, configure HTTPS, secure cookies, backups, private object storage and retention, mail/WhatsApp providers if desired, final business rules, and the payment/refund process. The app does not currently integrate a payment gateway or external notification provider.
