# 100K7N14L - Download Permissions

Adds module-level `DOWNLOAD` permissions to User & Role Management for every current Excel/PDF/report/supporting-document download surface.

Controls:
- Excel/PDF export toolbar is hidden unless the current role has `<module>.DOWNLOAD`.
- Sales Dashboard PNG download follows `sales-dashboard.DOWNLOAD`.
- Supporting-document downloads are enforced server-side and exposed only when the user has the matching module `DOWNLOAD` permission.
- Existing roles that already have VIEW access to a module receive DOWNLOAD during migration to preserve current behavior; permissions can then be revoked per role.
- Selecting DOWNLOAD in role management automatically retains VIEW through the existing role-permission service.

Requires migration.
