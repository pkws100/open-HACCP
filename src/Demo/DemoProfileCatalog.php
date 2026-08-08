<?php

declare(strict_types=1);

namespace Haccp\Demo;

final class DemoProfileCatalog
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            [
                'device_uid' => 'haccp-demo-fridge',
                'name' => 'Kühlschrank',
                'measurement_point' => 'fridge-main',
                'measurement_point_name' => 'Kühlschrank Innenraum',
                'location' => 'Vorbereitungsküche',
                'temperature_c' => 4.2,
                'temperature_min_c' => 2.0,
                'temperature_max_c' => 7.0,
                'humidity_rh' => 68.0,
                'battery_mv' => 6200,
                'rssi_dbm' => -50,
            ],
            [
                'device_uid' => 'haccp-demo-freezer',
                'name' => 'Gefriertruhe',
                'measurement_point' => 'freezer-main',
                'measurement_point_name' => 'Gefriertruhe Innenraum',
                'location' => 'Tiefkühllager',
                'temperature_c' => -20.0,
                'temperature_min_c' => -24.0,
                'temperature_max_c' => -18.0,
                'humidity_rh' => 42.0,
                'battery_mv' => 5800,
                'rssi_dbm' => -66,
            ],
            [
                'device_uid' => 'haccp-demo-milk-cooler',
                'name' => 'Getränkekühler – Milchgetränke',
                'measurement_point' => 'milk-cooler-main',
                'measurement_point_name' => 'Milchgetränke Kühlzone',
                'location' => 'Getränkeausgabe',
                'temperature_c' => 4.5,
                'temperature_min_c' => 2.0,
                'temperature_max_c' => 6.0,
                'humidity_rh' => 64.0,
                'battery_mv' => 5400,
                'rssi_dbm' => -82,
            ],
        ];
    }
}
