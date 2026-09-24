<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider default Bridge Tarif
    |--------------------------------------------------------------------------
    |
    | Kolom PROVID / PROVIDER_NAME yang masih kosong di file upload akan
    | diisi nilai ini saat generate. Sel yang sudah terisi tidak diubah.
    |
    */

    'provider_code' => env('BRIDGE_PROVIDER_CODE', 'OAZRA0-000'),
    'provider_name' => env('BRIDGE_PROVIDER_NAME', 'RS AZRA'),

];
