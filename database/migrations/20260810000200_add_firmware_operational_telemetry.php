<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class AddFirmwareOperationalTelemetry extends AbstractMigration
{
    public function up(): void
    {
        $this->table('devices')
            ->addColumn('device_info_json', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG, 'after' => 'firmware_version'])
            ->addColumn('last_applied_config_version', 'integer', ['null' => true, 'signed' => false, 'after' => 'device_info_json'])
            ->addColumn('last_config_status', 'string', ['null' => true, 'limit' => 24, 'after' => 'last_applied_config_version'])
            ->update();

        $this->table('device_transmissions')
            ->addColumn('device_info_json', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG, 'after' => 'hardware_revision'])
            ->addColumn('operational_status_json', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG, 'after' => 'device_info_json'])
            ->addColumn('applied_config_version', 'integer', ['null' => true, 'signed' => false, 'after' => 'operational_status_json'])
            ->addColumn('config_apply_status', 'string', ['null' => true, 'limit' => 24, 'after' => 'applied_config_version'])
            ->addColumn('queue_depth', 'integer', ['null' => true, 'signed' => false, 'after' => 'config_apply_status'])
            ->addColumn('wifi_failures_since_report', 'biginteger', ['null' => true, 'signed' => false, 'after' => 'queue_depth'])
            ->addColumn('upload_failures_since_report', 'biginteger', ['null' => true, 'signed' => false, 'after' => 'wifi_failures_since_report'])
            ->addColumn('sleep_fallbacks_since_report', 'biginteger', ['null' => true, 'signed' => false, 'after' => 'upload_failures_since_report'])
            ->addIndex(['device_id', 'applied_config_version'], ['name' => 'idx_transmissions_device_config_ack'])
            ->update();
    }

    public function down(): void
    {
        $this->table('device_transmissions')
            ->removeIndexByName('idx_transmissions_device_config_ack')
            ->removeColumn('sleep_fallbacks_since_report')
            ->removeColumn('upload_failures_since_report')
            ->removeColumn('wifi_failures_since_report')
            ->removeColumn('queue_depth')
            ->removeColumn('config_apply_status')
            ->removeColumn('applied_config_version')
            ->removeColumn('operational_status_json')
            ->removeColumn('device_info_json')
            ->update();

        $this->table('devices')
            ->removeColumn('last_config_status')
            ->removeColumn('last_applied_config_version')
            ->removeColumn('device_info_json')
            ->update();
    }
}
