<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateDevices extends AbstractMigration
{
    public function change(): void
    {
        $this->table('devices', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('device_uid', 'string', ['limit' => 64])
            ->addColumn('name', 'string', ['limit' => 160])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'active'])
            ->addColumn('hardware_revision', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('firmware_version', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('api_key_hash', 'char', ['limit' => 64])
            ->addColumn('last_seen_at', 'datetime', ['null' => true, 'precision' => 6])
            ->addColumn('last_ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('last_rssi_dbm', 'integer', ['null' => true])
            ->addColumn('last_battery_mv', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addColumn('updated_at', 'datetime', ['precision' => 6])
            ->addIndex(['device_uid'], ['unique' => true, 'name' => 'uq_devices_device_uid'])
            ->addIndex(['status'], ['name' => 'idx_devices_status'])
            ->create();
    }
}
