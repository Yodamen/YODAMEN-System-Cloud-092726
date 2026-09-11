# Phase 1B Change Manifest

## ADD NEW

### Laravel
- `backend/database/migrations/2026_09_12_000200_create_master_data_tables.php`
- `backend/database/seeders/MasterDataSeeder.php`
- `backend/app/Models/ReferenceGroup.php`
- `backend/app/Models/ReferenceValue.php`
- `backend/app/Models/Service.php`
- `backend/app/Models/ServicePrice.php`
- `backend/app/Models/Personnel.php`
- `backend/app/Services/MasterData/ReferenceDataService.php`
- `backend/app/Services/MasterData/ServiceCatalogService.php`
- `backend/app/Services/MasterData/PersonnelService.php`
- `backend/app/Http/Requests/Api/V1/Admin/SaveReferenceValueRequest.php`
- `backend/app/Http/Requests/Api/V1/Admin/SaveServiceRequest.php`
- `backend/app/Http/Requests/Api/V1/Admin/ChangeServicePriceRequest.php`
- `backend/app/Http/Requests/Api/V1/Hr/SavePersonnelRequest.php`
- `backend/app/Http/Controllers/Api/V1/Admin/ReferenceDataController.php`
- `backend/app/Http/Controllers/Api/V1/Admin/ServicesPricingController.php`
- `backend/app/Http/Controllers/Api/V1/Hr/PersonnelController.php`
- `backend/tests/Feature/MasterData/ReferenceDataTest.php`
- `backend/tests/Feature/MasterData/ServicesPricingTest.php`
- `backend/tests/Feature/MasterData/PersonnelTest.php`
- `backend/tests/Feature/Foundation/InitialSuperAdminSeederTest.php`

### React
- `frontend/src/components/common/Modal.jsx`
- `frontend/src/features/auth/ModuleRoute.jsx`
- `frontend/src/pages/admin/ReferenceListsPage.jsx`
- `frontend/src/pages/admin/ServicesPricingPage.jsx`
- `frontend/src/pages/hr/PersonnelPage.jsx`

### Documentation
- `docs/PHASE_1B_SETUP.md`
- `docs/PHASE_1B_CHANGE_MANIFEST.md`

## REPLACE / MODIFY

- `backend/routes/api.php`
- `backend/app/Models/User.php`
- `backend/app/Models/Business.php`
- `backend/database/seeders/DatabaseSeeder.php`
- `backend/database/seeders/InitialSuperAdminSeeder.php`
- `frontend/src/context/AuthContext.jsx`
- `frontend/src/components/navigation/navigationConfig.js`
- `frontend/src/router/index.jsx`
- `frontend/src/pages/DashboardPage.jsx`
- `frontend/src/index.css`
- `docs/MIGRATION_STATUS.md`
- `docs/PARITY_MATRIX.md`
- `docs/LEGACY_SOURCE_MAP.md`

## DO NOT CHANGE IN PHASE 1B

- `.env` files
- existing Access Code
- Booking/Queue/Visit schema or workflows
- Sales/Payment/Payment Application schema or workflows
- Package/Service Credit transaction logic
- Commission earning logic
- Attendance transaction logic
- Payroll transaction logic
- Profit Sharing run logic
