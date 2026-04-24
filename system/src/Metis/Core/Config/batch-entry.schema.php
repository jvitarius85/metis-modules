<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

return [
    'contacts:create' => [
        'module' => 'contacts',
        'action' => 'create',
        'nonce_action' => 'metis_contacts',
        'permission_callback' => 'metis_contacts_can_manage',
        'processor' => 'metis_contacts_create_contact',
        'fields' => [
            [
                'key' => 'first_name',
                'label' => 'First Name',
                'type' => 'text',
                'required' => true,
                'max_length' => 120,
            ],
            [
                'key' => 'last_name',
                'label' => 'Last Name',
                'type' => 'text',
                'required' => true,
                'max_length' => 120,
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'type' => 'email',
                'required' => true,
                'max_length' => 180,
                'unique_in_batch' => true,
            ],
            [
                'key' => 'phone',
                'label' => 'Phone',
                'type' => 'text',
                'required' => false,
                'max_length' => 50,
            ],
        ],
        'totals' => [
            [
                'key' => 'email',
                'type' => 'count',
                'label' => 'Rows Entered',
            ],
        ],
        'row_validators' => [
            'metis_batch_validate_contacts_create_row',
        ],
    ],
    'newsletter:subscribers-create' => [
        'module' => 'newsletter',
        'action' => 'subscribers-create',
        'nonce_action' => 'metis_newsletter',
        'permission_callback' => 'metis_newsletter_can_manage',
        'processor' => 'metis_newsletter_upsert_subscription_record',
        'fields' => [
            [
                'key' => 'first_name',
                'label' => 'First Name',
                'type' => 'text',
                'required' => false,
                'max_length' => 120,
            ],
            [
                'key' => 'last_name',
                'label' => 'Last Name',
                'type' => 'text',
                'required' => false,
                'max_length' => 120,
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'type' => 'email',
                'required' => true,
                'max_length' => 180,
                'unique_in_batch' => true,
            ],
            [
                'key' => 'list_id',
                'label' => 'List',
                'type' => 'number',
                'required' => true,
            ],
            [
                'key' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'required' => false,
                'allowed_values' => [ 'subscribed', 'unsubscribed', 'bounced', 'rejected' ],
            ],
        ],
        'totals' => [
            [
                'key' => 'email',
                'type' => 'count',
                'label' => 'Rows Entered',
            ],
        ],
        'row_validators' => [
            'metis_batch_validate_newsletter_subscription_row',
        ],
    ],
];
