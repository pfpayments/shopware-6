Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'weareplanet',
    key: 'weareplanet',
    roles: {
        viewer: {
            privileges: [
                'sales_channel:read',
                'sales_channel_payment_method:read',
                'system_config:read'
            ],
            dependencies: []
        },
        editor: {
            privileges: [
                'sales_channel:update',
                'sales_channel_payment_method:create',
                'sales_channel_payment_method:update',
                'system_config:update',
                'system_config:create',
                'system_config:delete'
            ],
            dependencies: [
                'weareplanet.viewer'
            ]
        }
    }
});

Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'sales_channel',
    roles: {
        viewer: {
            privileges: [
                'sales_channel_payment_method:read'
            ]
        },
        editor: {
            privileges: [
                'payment_method:update'
            ]
        },
        creator: {
            privileges: [
                'payment_method:create',
                'shipping_method:create',
                'delivery_time:create'
            ]
        },
        deleter: {
            privileges: [
                'payment_method:delete'
            ]
        }
    }
});
