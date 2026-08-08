<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddBatteryThresholdsToDeviceConfigs extends AbstractMigration
{
    public function up(): void
    {
        $this->table('device_configs')
            ->addColumn('battery_low_mv', 'integer', [
                'signed' => false,
                'default' => 5600,
                'after' => 'temperature_max_c',
            ])
            ->addColumn('battery_full_mv', 'integer', [
                'signed' => false,
                'default' => 6000,
                'after' => 'battery_low_mv',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('device_configs')
            ->removeColumn('battery_full_mv')
            ->removeColumn('battery_low_mv')
            ->update();
    }
}
