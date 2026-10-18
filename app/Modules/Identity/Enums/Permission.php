<?php

namespace App\Modules\Identity\Enums;

/**
 * Coarse capability flags for administrative functions.
 *
 * Patient, doctor and pharmacy access to *records* is never granted by a
 * permission alone: policies additionally check the relationship (care
 * relationship, assignment, branch membership) for every object.
 */
enum Permission: string
{
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case DoctorsVerify = 'doctors.verify';
    case PharmaciesVerify = 'pharmacies.verify';
    case SpecialtiesManage = 'specialties.manage';
    case AppointmentsMonitor = 'appointments.monitor';
    case PaymentsView = 'payments.view';
    case PaymentsRefund = 'payments.refund';
    case SettlementsManage = 'settlements.manage';
    case ComplaintsManage = 'complaints.manage';
    case ReviewsModerate = 'reviews.moderate';
    case ReportsView = 'reports.view';
    case AuditView = 'audit.view';
    case SettingsManage = 'settings.manage';
    case AdminsManage = 'admins.manage';
    case IntegrationsManage = 'integrations.manage';
    case SecurityManage = 'security.manage';

    /**
     * Default permission set per role. Super administrators receive every permission.
     *
     * @return array<string, list<string>>
     */
    public static function defaultsByRole(): array
    {
        $admin = [
            self::UsersView, self::UsersManage, self::DoctorsVerify, self::PharmaciesVerify,
            self::SpecialtiesManage, self::AppointmentsMonitor, self::PaymentsView,
            self::PaymentsRefund, self::SettlementsManage, self::ComplaintsManage,
            self::ReviewsModerate, self::ReportsView, self::AuditView, self::SettingsManage,
        ];

        return [
            RoleName::Patient->value => [],
            RoleName::Doctor->value => [],
            RoleName::PharmacyStaff->value => [],
            RoleName::Admin->value => array_map(fn (self $p) => $p->value, $admin),
            RoleName::SuperAdmin->value => array_map(fn (self $p) => $p->value, self::cases()),
        ];
    }
}
