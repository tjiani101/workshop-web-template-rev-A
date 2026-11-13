# Additional work approval

During inspection, a mechanic records a finding with severity, customer-facing explanation, technical note, and optional private media. A recommendation can link to a finding and an active service or part variant. Its estimate is calculated from server-side catalog prices and the stated quantity.

Part recommendations use the exact vehicle model snapshot from the booking. A verified compatible variant carries its catalog fitment record into the recommendation. Unverified fitment requires the mechanic to record a confirmation note and actor/time. A variant marked incompatible is rejected. Customers cannot accept a part recommendation until fitment is compatible or mechanically confirmed.

Recommendations keep their current pending/accepted/declined state and an append-only decision history with actor, time, and note. Customers can change a decision while its work item is still pending; starting an item in the mechanic workspace closes customer changes for that item. An administrator can override a recorded choice with a required reason, and the override is audited and shown in the history. Completed or skipped work cannot be changed through the decision workflow.

Accepting a part reserves stock in the same transaction that records the decision and adds a work-order item and additional charge. Declining releases an outstanding reservation and removes the additional charge without deleting its historical item or decision. Accept-all and decline-all apply to pending recommendations atomically. Work cannot proceed past customer approval while any recommendation remains pending. Private inspection photos are served only to the assigned mechanic and the booking customer; staff financial routes remain admin-only.
