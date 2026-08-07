<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateDeviceTransmissions extends AbstractMigration
{
    public function change(): void
    {
        $this->table('device_transmissions', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('device_id', 'biginteger', ['signed' => false])
            ->addColumn('transmission_type', 'string', ['limit' => 24])
            ->addColumn('request_id', 'string', ['limit' => 64])
            ->addColumn('batch_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('received_at', 'datetime', ['precision' => 6])
            ->addColumn('firmware_version', 'string', ['limit' => 64])
            ->addColumn('hardware_revision', 'string', ['limit' => 64])
            ->addColumn('battery_mv', 'integer', ['signed' => false])
            ->addColumn('rssi_dbm', 'integer')
            ->addColumn('wifi_connect_ms', 'integer', ['signed' => false])
            ->addColumn('boot_count', 'biginteger', ['signed' => false])
            ->addColumn('measurement_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('accepted_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('duplicate_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rejected_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('remote_ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addIndex(['device_id', 'received_at'], ['name' => 'idx_device_transmissions_device_received'])
            ->addIndex(['device_id', 'batch_id'], ['name' => 'idx_device_transmissions_device_batch'])
            ->addIndex(['request_id'], ['name' => 'idx_device_transmissions_request'])
            ->addForeignKey('device_id', 'devices', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();
    }
}
