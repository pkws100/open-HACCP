<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateMeasurements extends AbstractMigration
{
    public function change(): void
    {
        $this->table('measurements', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('device_id', 'biginteger', ['signed' => false])
            ->addColumn('measurement_point_id', 'biginteger', ['signed' => false])
            ->addColumn('sequence', 'biginteger', ['signed' => false])
            ->addColumn('measured_at', 'datetime', ['precision' => 6])
            ->addColumn('received_at', 'datetime', ['precision' => 6])
            ->addColumn('temperature_c', 'decimal', ['precision' => 7, 'scale' => 3])
            ->addColumn('humidity_rh', 'decimal', ['precision' => 6, 'scale' => 3])
            ->addColumn('battery_mv', 'integer', ['signed' => false])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addIndex(['device_id', 'measurement_point_id', 'sequence'], ['unique' => true, 'name' => 'uq_measurements_identity'])
            ->addIndex(['device_id'], ['name' => 'idx_measurements_device'])
            ->addIndex(['measurement_point_id', 'measured_at'], ['name' => 'idx_measurements_point_measured'])
            ->addIndex(['device_id', 'measurement_point_id', 'measured_at'], ['name' => 'idx_measurements_device_point_time'])
            ->addForeignKey('device_id', 'devices', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('measurement_point_id', 'measurement_points', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();
    }
}
