# Inventory workflow

Stock is tracked per variant and workshop location. For a stock level:

- Physical quantity is the quantity on hand.
- Reserved quantity is held for approved work but remains physical.
- Available quantity is physical minus reserved.

Admins record receipts with purchase cost, supplier/reference, and note. Adjustments require a reason, cannot reduce stock below existing reservations, and create a stock movement. Low-stock alerts compare available quantity to the reorder threshold.

The system reserves booking parts after required payment confirmation when stock is available. If the initial reservation cannot be completed, successful reservations are released and the work order enters `waiting_parts`; staff can reserve each approved item when stock arrives. Accepted recommendation parts reserve inside the customer decision transaction. Declined recommendations create no reservation. Completing a work-order item consumes its linked reservation; skipping releases it. Refund or unpaid-booking expiration releases active reservations.

Inventory changes use transactions and row locks; a user cannot submit a negative available balance. Purchase orders, supplier accounts, multi-location transfers, and override permissions are not implemented.
