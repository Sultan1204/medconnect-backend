<?php

/*
| Notification texts. They are shown in SMS, e-mail, push and the in-app feed,
| so they stay generic: no clinical details, doctor names, specialties or
| medication names. Keys mirror App\Modules\Notifications\Enums\NotificationType.
*/

return [
    'appointment' => [
        'new_booking' => ['title' => 'New appointment', 'body' => 'You have a new appointment on :time. Open MedConnect to respond.'],
        'confirmed' => ['title' => 'Appointment confirmed', 'body' => 'Your MedConnect appointment on :time is confirmed.'],
        'reminder' => ['title' => 'Appointment reminder', 'body' => 'Reminder: your MedConnect appointment is on :time.'],
        'cancelled' => ['title' => 'Appointment cancelled', 'body' => 'The MedConnect appointment on :time was cancelled.'],
    ],
    'payment' => [
        'confirmed' => ['title' => 'Payment received', 'body' => 'We received your payment of :amount. Thank you.'],
    ],
    'refund' => [
        'succeeded' => ['title' => 'Refund issued', 'body' => 'A refund of :amount has been issued to your original payment method.'],
    ],
    'message' => [
        'received' => ['title' => 'New message', 'body' => 'You have a new message in one of your consultations.'],
    ],
    'consultation' => [
        'started' => ['title' => 'Consultation started', 'body' => 'Your consultation has started. Open MedConnect to join.'],
        'emergency' => ['title' => 'Urgent: seek in-person care', 'body' => 'Your doctor recommends urgent in-person medical care. If this is an emergency, call :numbers immediately. MedConnect is not an emergency service.'],
    ],
    'prescription' => [
        'status_changed' => ['title' => 'Prescription update', 'body' => 'One of your prescriptions is now :status. Open MedConnect for details.'],
    ],
    'pharmacy' => [
        'request_updated' => ['title' => 'Pharmacy request update', 'body' => 'Your pharmacy request is now: :status.'],
        'request_received' => ['title' => 'New pharmacy request', 'body' => 'A new prescription request is waiting for your branch.'],
    ],
    'security' => [
        'new_device' => ['title' => 'New sign-in', 'body' => 'Your MedConnect account was signed in on a new device. If this was not you, reset your password immediately.'],
        'account_locked' => ['title' => 'Account locked', 'body' => 'Your MedConnect account was temporarily locked after several failed sign-in attempts.'],
    ],
    'complaint' => [
        'updated' => ['title' => 'Complaint update', 'body' => 'There is an update on your complaint :reference. Open MedConnect for details.'],
    ],
    'data_request' => [
        'updated' => ['title' => 'Personal data request update', 'body' => 'There is an update on your personal data request. Open MedConnect for details.'],
    ],

    'prescription_status' => [
        'issued' => 'issued',
        'rejected' => 'rejected',
        'cancelled' => 'cancelled',
        'expired' => 'expired',
        'dispensed' => 'dispensed',
    ],
    'pharmacy_status' => [
        'received' => 'received by the pharmacy',
        'under_review' => 'under review',
        'ready' => 'ready for collection',
        'dispensed' => 'dispensed',
        'rejected' => 'rejected by the pharmacy',
        'cancelled' => 'cancelled',
    ],

    'email_footer' => 'This message was sent by MedConnect. MedConnect is not an emergency service.',
];
