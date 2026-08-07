<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateMeasurementPointsAndConfigs extends AbstractMigration
{
    public function change(): void
    {
        $this->table('measurement_points', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('device_id', 'biginteger', ['signed' => false])
            ->addColumn('code', 'string', ['limit' => 64])
            ->addColumn('name', 'string', ['limit' => 160])
            ->addColumn('sensor_type', 'string', ['limit' => 64])
            ->addColumn('location', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addColumn('temperature_min_c', 'decimal', ['precision' => 7, 'scale' => 3, 'null' => true])
            ->addColumn('temperature_max_c', 'decimal', ['precision' => 7, 'scale' => 3, 'null' => true])
            ->addColumn('humidity_min_rh', 'decimal', ['precision' => 6, 'scale' => 3, 'null' => true])
            ->addColumn('humidity_max_rh', 'decimal', ['precision' => 6, 'scale' => 3, 'null' => true])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addColumn('updated_at', 'datetime', ['precision' => 6])
            ->addIndex(['device_id', 'code'], ['unique' => true, 'name' => 'uq_measurement_points_device_code'])
            ->addIndex(['device_id', 'active'], ['name' => 'idx_measurement_points_device_active'])
            ->addForeignKey('device_id', 'devices', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        $this->table('device_configs', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('device_id', 'biginteger', ['signed' => false])
            ->addColumn('config_version', 'integer', ['signed' => false])
            ->addColumn('measurement_interval_seconds', 'integer', ['signed' => false])
            ->addColumn('upload_interval_seconds', 'integer', ['signed' => false])
            ->addColumn('max_batch_size', 'integer', ['signed' => false, 'default' => 500])
            ->addColumn('alarm_enabled', 'boolean', ['default' => false])
            ->addColumn('temperature_min_c', 'decimal', ['precision' => 7, 'scale' => 3, 'null' => true])
            ->addColumn('temperature_max_c', 'decimal', ['precision' => 7, 'scale' => 3, 'null' => true])
            ->addColumn('config_json', 'text', ['null' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addColumn('updated_at', 'datetime', ['precision' => 6])
            ->addIndex(['device_id', 'config_version'], ['unique' => true, 'name' => 'uq_device_configs_device_version'])
            ->addForeignKey('device_id', 'devices', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();
    }
}
