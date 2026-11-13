# Maintenance package builder

A package groups required and optional service/part/inspection/cleaning items. Each item records its type, catalog reference, label snapshot, quantity, required flag, and optional extra amount. Package metadata includes description, category, base/labor price, discount, duration, compatibility, image, active status, and public visibility.

Admin editing uses a transaction: validate all item references and quantities, persist the package and ordered items, then publish. Customer selections are validated and repriced on the server. A booking retains a complete historical package/item snapshot. Example Full and Semi Maintenance packages are demo content; their prices and vehicle fitment are explicitly unverified until MINEX supplies authoritative data.
