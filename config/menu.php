<?php

/*
|--------------------------------------------------------------------------
| Menu Sidebar
|--------------------------------------------------------------------------
|
| Struktur menu sidebar berbasis konfigurasi.
| Setiap grup punya 'title' + 'menus'.
| Item menu bisa berupa:
|   - Link tunggal  : 'permission' (string) + 'route' + 'route_pattern' + 'icon'
|   - Dropdown      : 'permissions' (array) + 'children' (array link tunggal)
|
| 'route_pattern' dipakai untuk menentukan status aktif (request()->routeIs).
| Ikon memakai Font Awesome tanpa prefix, contoh: 'fa-tachometer-alt'.
|
*/

return [
    [
        'title' => 'Menu Utama',
        'menus' => [
            [
                'label' => 'Dashboard',
                'icon' => 'bi-grid-fill',
                'route' => 'dashboard',
                'route_pattern' => 'dashboard',
                'permission' => 'view_dashboard',
            ],
        ],
    ],
    [
        'title' => 'Pengaturan',
        'menus' => [
            [
                'label' => 'Pengguna & Akses',
                'icon' => 'bi-person-fill-gear',
                'permissions' => ['manage_users', 'manage_roles', 'manage_permissions'],
                'children' => [
                    [
                        'label' => 'Users',
                        'icon' => 'bi-person-fill-gear',
                        'route' => 'users.index',
                        'route_pattern' => 'users.*',
                        'permission' => 'manage_users',
                    ],
                    [
                        'label' => 'Roles',
                        'icon' => 'bi-person-fill-check',
                        'route' => 'roles.index',
                        'route_pattern' => 'roles.*',
                        'permission' => 'manage_roles',
                    ],
                    [
                        'label' => 'Permissions',
                        'icon' => 'bi-key-fill',
                        'route' => 'permissions.index',
                        'route_pattern' => 'permissions.*',
                        'permission' => 'manage_permissions',
                    ],
                ],
            ],
        ],
    ],
    [
        'title' => 'Organisasi',
        'menus' => [
            [
                'label' => 'Organisasi',
                'icon' => 'bi-buildings-fill',
                'permissions' => ['manage_organization_types', 'manage_organization_units'],
                'children' => [
                    [
                        'label' => 'Tipe Organisasi',
                        'icon' => 'bi-diagram-3-fill',
                        'route' => 'organization-types.index',
                        'route_pattern' => 'organization-types.*',
                        'permission' => 'manage_organization_types',
                    ],
                    [
                        'label' => 'Unit Organisasi',
                        'icon' => 'bi-buildings-fill',
                        'route' => 'organization-units.index',
                        'route_pattern' => 'organization-units.*',
                        'permission' => 'manage_organization_units',
                    ],
                ],
            ],
        ],
    ],
    [
        'title' => 'Laporan',
        'menus' => [
            [
                'label' => 'Audit Log',
                'icon' => 'bi-clock-history',
                'route' => 'activity-logs.index',
                'route_pattern' => 'activity-logs.*',
                'permission' => 'view_activity_logs',
            ],
        ],
    ],
];
