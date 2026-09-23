<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AllowMissingBatteryMeasurements extends AbstractMigration
{
    public function up(): void
    {
        $this->table('measurements')
            ->changeColumn('battery_mv', 'integer', ['signed' => false, 'null' => true])
            ->update();
        $this->table('device_transmissions')
            ->changeColumn('battery_mv', 'integer', ['signed' => false, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        // Existing NULLs have no truthful millivolt replacement.
        if ((int) $this->fetchRow('SELECT COUNT(*) AS count FROM measurements WHERE battery_mv IS NULL')['count'] > 0
            || (int) $this->fetchRow('SELECT COUNT(*) AS count FROM device_transmissions WHERE battery_mv IS NULL')['count'] > 0) {
            throw new RuntimeException('Cannot make battery_mv mandatory while batteryless records exist.');
        }
        $this->table('measurements')
            ->changeColumn('battery_mv', 'integer', ['signed' => false, 'null' => false])
            ->update();
        $this->table('device_transmissions')
            ->changeColumn('battery_mv', 'integer', ['signed' => false, 'null' => false])
            ->update();
    }
}
