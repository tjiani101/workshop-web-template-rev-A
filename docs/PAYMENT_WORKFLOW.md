# Payment and expense workflow

All financial amounts are integer Rupiah. Customers submit a payment amount, method, reference, and optional private proof. The application validates the amount against the remaining deposit/balance and marks the record pending review. Only an authorized admin can verify or reject it; verification records an immutable decision and audit event.

When net verified payments reach the configured member deposit percentage (guests require full payment), the booking and appointment become confirmed. The initial work order is opened and selected parts are reserved where stock is available. Remaining amounts include customer-approved additional work. A mechanic cannot hand over a motorcycle while the net paid amount is below the approved total.

Admins can request a refund up to verified payments less pending/processed refunds, then record a processing decision and reference. Refunds do not rewrite payment rows. Full refunds cancel future appointments and release active stock reservations. The app records the ledger action; it does not issue a bank transfer or connect to a payment gateway.

Workshop expenses are recorded with date, category, amount, payment method, optional reference, and private receipt. An expense can be voided with an actor and reason; the original record remains in the ledger. Dashboard revenue is verified gross receipts for today and expenses are recorded expenses for today, not accounting statements.

Payment gateway callbacks, automated settlement, tax calculations, and outbound receipts are not configured. MINEX must confirm provider and accounting rules before production integration.
