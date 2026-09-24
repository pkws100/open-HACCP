<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTemperatureCalibrationToMeasurements extends AbstractMigration
{
    public function up(): void
    {
        $this->table('measurements')
            ->addColumn('corrected_temperature_c', 'decimal', ['precision' => 7, 'scale' => 3, 'null' => true, 'after' => 'temperature_c'])
            ->addColumn('applied_temperature_offset_c', 'decimal', ['precision' => 6, 'scale' => 3, 'null' => true, 'after' => 'corrected_temperature_c'])
            ->addColumn('calibration_config_version', 'integer', ['signed' => false, 'null' => true, 'after' => 'applied_temperature_offset_c'])
            ->update();
    }

    public function down(): void
    {
        $this->table('measurements')
            ->removeColumn('calibration_config_version')
            ->removeColumn('applied_temperature_offset_c')
            ->removeColumn('corrected_temperature_c')
            ->update();
    }
}
