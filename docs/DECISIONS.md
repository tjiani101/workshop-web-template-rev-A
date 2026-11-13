# Decision register

## Confirmed business rules

- MINEX serves everyday motorcycle riders, including automatic, manual, underbone, and configurable other categories.
- Catalog and package contents must be manageable without source changes.
- Disabled categories/services disappear from new public offers while historical records remain.
- Customers must consent to additional work; accepted work reserves parts and completed work records actual consumption.
- Prices are server-calculated and transaction snapshots remain unchanged after catalog edits.
- No unverified compatibility, invented real prices, certifications, business contact details, or testimonials.
- Demo accounts are development-only and must never be enabled automatically in production.
- The current booking form offers member deposits of 10%, 25%, 50%, or 100%; guests pay the full approved amount. MINEX must confirm these choices before production.
- Workshop service-bay capacity is configurable and starts conservatively at one bay until the workshop sets its real capacity.

## Technical recommendations adopted for the foundation

- Laravel 13, PHP 8.3+, Blade, Eloquent, MariaDB/MySQL, custom CSS, and vanilla JavaScript.
- Store Rupiah as integer amounts; use normalized role and permission tables.
- Use a session cookie and Laravel CSRF protection; local sessions are encrypted and file-backed.
- Use private storage for inspection media and public storage only for catalog imagery.
- Use `Asia/Jakarta` as the initial configurable timezone because this development environment is in Jakarta; confirm the workshop's operating timezone before scheduling goes live.
- Offer 30-minute candidate start-time increments while requiring availability checks to cover the full service duration; the selected candidate remains a request until an administrator confirms it.
- Use local-only `.env` credentials and a `minex_dev` account scoped to the local demo and test databases on this workstation.

## Items requiring owner approval

- Production country, locale, currency, timezone, business name/legal identity, contact channels, location, and hours.
- Real catalog labor rates, package pricing, discount policy, taxes, warranty/terms, and final compatibility data.
- Booking lead time, opening days, candidate start-time increment, capacity, cancellation/no-show, guest booking, and approval rules. Candidate times currently use a 30-minute increment; customers submit a service request and admins assign the appointment after review.
- Active mechanic profiles must be assigned only to service categories they are qualified to perform. Candidate-time results stay empty until at least one eligible team can cover the requested work.
- Required customer data and data retention/deletion periods, especially for contact requests and inspection media.
- Accepted payment methods, deposit requirements, refund policy, and who may verify payments.
- Whether a customer may book before account creation and what proof of vehicle ownership is required.
- Production hosting, backups, email/WhatsApp providers, and payment gateway.
