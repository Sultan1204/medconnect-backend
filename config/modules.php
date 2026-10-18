<?php

/*
|--------------------------------------------------------------------------
| Module registry
|--------------------------------------------------------------------------
| Route files are loaded in this order under /api/v1. Add a module's file
| here when the module gains HTTP endpoints (ArchitectureTest enforces it).
*/

return [
    'routes' => [
        'app/Modules/Identity/routes.php',
        'app/Modules/Specialties/routes.php',
        'app/Modules/Patients/routes.php',
        'app/Modules/Doctors/routes.php',
        'app/Modules/Scheduling/routes.php',
        'app/Modules/Consultations/routes.php',
        'app/Modules/Messaging/routes.php',
        'app/Modules/Video/routes.php',
        'app/Modules/MedicalRecords/routes.php',
        'app/Modules/Payments/routes.php',
        'app/Modules/Settlements/routes.php',
        'app/Modules/Prescriptions/routes.php',
        'app/Modules/Pharmacies/routes.php',
        'app/Modules/Notifications/routes.php',
        'app/Modules/Reviews/routes.php',
        'app/Modules/Administration/routes.php',
    ],
];
