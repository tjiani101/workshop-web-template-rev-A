# Mechanic workflow

Mechanics see appointments assigned to their profile and can open only work orders linked to those assignments. The work order shows the customer's approved booking snapshot, vehicle, notes, payment state, inspection findings, checklists, and work items.

The mechanic records odometer and arrival condition, submits inspection findings with private photos, and may recommend additional catalog labor or parts. Prices come from active server-side catalog records. If part fitment is not already verified for the exact motorcycle, a mechanic must confirm fitment and save a note before sending the recommendation to the customer. Catalog records marked incompatible cannot be recommended.

Customers can accept or decline all pending recommendations or decide individually. Decisions are kept in an append-only history and may be changed while their work item is still pending. A mechanic starts each item explicitly; a started item can no longer be changed by the customer, though an administrator may record an audited override with a reason. Accepted parts reserve stock; completing a part work item consumes that exact reservation, while skipping or declining before completion releases it. Work order completion requires all items and checklists to be closed. Handover requires the verified balance after refunds to cover approved work.

The current checklist is created when payment confirms the booking. MINEX should review the checklist and handover process against its workshop quality requirements before production use.
