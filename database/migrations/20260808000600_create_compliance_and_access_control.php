<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class CreateComplianceAndAccessControl extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('username', 'string', ['limit' => 80])
            ->addColumn('display_name', 'string', ['limit' => 160])
            ->addColumn('email', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('role', 'string', ['limit' => 24])
            ->addColumn('password_hash', 'string', ['limit' => 255])
            ->addColumn('password_change_required', 'boolean', ['default' => true])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addColumn('failed_login_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('locked_until', 'datetime', ['precision' => 6, 'null' => true])
            ->addColumn('last_login_at', 'datetime', ['precision' => 6, 'null' => true])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addColumn('updated_at', 'datetime', ['precision' => 6])
            ->addIndex(['username'], ['unique' => true, 'name' => 'uq_users_username'])
            ->addIndex(['role', 'active'], ['name' => 'idx_users_role_active'])
            ->create();

        $this->table('user_sessions', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64])
            ->addColumn('csrf_token', 'char', ['limit' => 64])
            ->addColumn('last_seen_at', 'datetime', ['precision' => 6])
            ->addColumn('idle_expires_at', 'datetime', ['precision' => 6])
            ->addColumn('absolute_expires_at', 'datetime', ['precision' => 6])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_user_sessions_token'])
            ->addIndex(['user_id', 'absolute_expires_at'], ['name' => 'idx_user_sessions_user_expiry'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('login_attempts', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('username_hash', 'char', ['limit' => 64])
            ->addColumn('successful', 'boolean', ['default' => false])
            ->addColumn('attempted_at', 'datetime', ['precision' => 6])
            ->addIndex(['username_hash', 'attempted_at'], ['name' => 'idx_login_attempts_username_time'])
            ->create();

        $this->table('establishments', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'integer', ['signed' => false])
            ->addColumn('legal_name', 'string', ['limit' => 200, 'default' => ''])
            ->addColumn('trade_name', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('address_line1', 'string', ['limit' => 200, 'default' => ''])
            ->addColumn('address_line2', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('postal_code', 'string', ['limit' => 20, 'default' => ''])
            ->addColumn('city', 'string', ['limit' => 120, 'default' => ''])
            ->addColumn('country_code', 'char', ['limit' => 2, 'default' => 'DE'])
            ->addColumn('authority_reference', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('timezone', 'string', ['limit' => 64, 'default' => 'Europe/Berlin'])
            ->addColumn('haccp_responsible_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('general_retention_months', 'integer', ['signed' => false, 'default' => 24])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addColumn('updated_at', 'datetime', ['precision' => 6])
            ->addForeignKey('haccp_responsible_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        $now = date('Y-m-d H:i:s.u');
        $this->execute(sprintf(
            "INSERT INTO establishments (id, legal_name, address_line1, postal_code, city, country_code, timezone, general_retention_months, created_at, updated_at) VALUES (1, '', '', '', '', 'DE', 'Europe/Berlin', 24, '%s', '%s')",
            $now,
            $now,
        ));

        $this->table('measurement_point_compliance_configs', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('measurement_point_id', 'biginteger', ['signed' => false])
            ->addColumn('config_version', 'integer', ['signed' => false])
            ->addColumn('legal_profile', 'string', ['limit' => 32, 'default' => 'general_haccp'])
            ->addColumn('control_classification', 'string', ['limit' => 16, 'default' => 'GHP'])
            ->addColumn('monitoring_purpose', 'string', ['limit' => 255, 'default' => 'Temperaturüberwachung'])
            ->addColumn('humidity_is_critical', 'boolean', ['default' => false])
            ->addColumn('retention_months', 'integer', ['signed' => false, 'default' => 24])
            ->addColumn('responsible_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('instrument_manufacturer', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('instrument_model', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('instrument_serial', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('conformity_status', 'string', ['limit' => 32, 'default' => 'not_documented'])
            ->addColumn('conformity_reference', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('calibration_reference', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('verification_reference', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('calibrated_at', 'date', ['null' => true])
            ->addColumn('verification_due_at', 'date', ['null' => true])
            ->addColumn('effective_from', 'datetime', ['precision' => 6])
            ->addColumn('created_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addIndex(['measurement_point_id', 'config_version'], ['unique' => true, 'name' => 'uq_point_compliance_version'])
            ->addIndex(['measurement_point_id', 'effective_from'], ['name' => 'idx_point_compliance_effective'])
            ->addForeignKey('measurement_point_id', 'measurement_points', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('responsible_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        $this->execute(
            "INSERT INTO measurement_point_compliance_configs
             (measurement_point_id, config_version, legal_profile, control_classification, monitoring_purpose,
              humidity_is_critical, retention_months, conformity_status, effective_from, created_at)
             SELECT id, 1, 'general_haccp', 'GHP', 'Temperaturüberwachung', 0, 24,
                    'not_documented', created_at, created_at FROM measurement_points",
        );

        $this->table('battery_cycles', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('device_id', 'biginteger', ['signed' => false])
            ->addColumn('started_at', 'datetime', ['precision' => 6])
            ->addColumn('chemistry', 'string', ['limit' => 64, 'default' => 'unspecified'])
            ->addColumn('series_count', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('nominal_capacity_mah', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('forecast_enabled', 'boolean', ['default' => true])
            ->addColumn('recorded_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addIndex(['device_id', 'started_at'], ['name' => 'idx_battery_cycles_device_started'])
            ->addForeignKey('device_id', 'devices', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('recorded_by_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        $this->table('compliance_events', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('device_id', 'biginteger', ['signed' => false])
            ->addColumn('measurement_point_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('event_type', 'string', ['limit' => 48])
            ->addColumn('severity', 'string', ['limit' => 16])
            ->addColumn('state', 'string', ['limit' => 24, 'default' => 'open'])
            ->addColumn('opened_at', 'datetime', ['precision' => 6])
            ->addColumn('closed_at', 'datetime', ['precision' => 6, 'null' => true])
            ->addColumn('acknowledged_at', 'datetime', ['precision' => 6, 'null' => true])
            ->addColumn('acknowledged_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('threshold_min', 'decimal', ['precision' => 10, 'scale' => 3, 'null' => true])
            ->addColumn('threshold_max', 'decimal', ['precision' => 10, 'scale' => 3, 'null' => true])
            ->addColumn('observed_value', 'decimal', ['precision' => 10, 'scale' => 3, 'null' => true])
            ->addColumn('source_measurement_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('source_transmission_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('metadata_json', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addColumn('updated_at', 'datetime', ['precision' => 6])
            ->addIndex(['device_id', 'state', 'opened_at'], ['name' => 'idx_events_device_state_time'])
            ->addIndex(['measurement_point_id', 'event_type', 'state'], ['name' => 'idx_events_point_type_state'])
            ->addIndex(['event_type', 'opened_at'], ['name' => 'idx_events_type_time'])
            ->addForeignKey('device_id', 'devices', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('measurement_point_id', 'measurement_points', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('acknowledged_by_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->addForeignKey('source_measurement_id', 'measurements', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->addForeignKey('source_transmission_id', 'device_transmissions', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        $this->table('corrective_actions', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('event_id', 'biginteger', ['signed' => false])
            ->addColumn('current_revision', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('state', 'string', ['limit' => 24, 'default' => 'recorded'])
            ->addColumn('created_by_user_id', 'biginteger', ['signed' => false])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addColumn('updated_at', 'datetime', ['precision' => 6])
            ->addIndex(['event_id'], ['name' => 'idx_corrective_actions_event'])
            ->addForeignKey('event_id', 'compliance_events', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        $this->table('corrective_action_revisions', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('corrective_action_id', 'biginteger', ['signed' => false])
            ->addColumn('revision', 'integer', ['signed' => false])
            ->addColumn('cause', 'text')
            ->addColumn('action_taken', 'text')
            ->addColumn('product_disposition', 'text')
            ->addColumn('preventive_follow_up', 'text', ['null' => true])
            ->addColumn('performed_at', 'datetime', ['precision' => 6])
            ->addColumn('responsible_user_id', 'biginteger', ['signed' => false])
            ->addColumn('created_by_user_id', 'biginteger', ['signed' => false])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addIndex(['corrective_action_id', 'revision'], ['unique' => true, 'name' => 'uq_action_revision'])
            ->addForeignKey('corrective_action_id', 'corrective_actions', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('responsible_user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        $this->table('event_verifications', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('corrective_action_id', 'biginteger', ['signed' => false])
            ->addColumn('verified_by_user_id', 'biginteger', ['signed' => false])
            ->addColumn('verified_at', 'datetime', ['precision' => 6])
            ->addColumn('note', 'text')
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addIndex(['corrective_action_id', 'verified_at'], ['name' => 'idx_verifications_action_time'])
            ->addForeignKey('corrective_action_id', 'corrective_actions', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('verified_by_user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        $this->table('audit_log', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('actor_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('action', 'string', ['limit' => 80])
            ->addColumn('entity_type', 'string', ['limit' => 48, 'null' => true])
            ->addColumn('entity_id', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('payload_json', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG])
            ->addColumn('previous_hash', 'char', ['limit' => 64])
            ->addColumn('entry_hash', 'char', ['limit' => 64])
            ->addColumn('occurred_at', 'datetime', ['precision' => 6])
            ->addIndex(['occurred_at'], ['name' => 'idx_audit_log_time'])
            ->addIndex(['actor_user_id', 'occurred_at'], ['name' => 'idx_audit_log_actor_time'])
            ->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();

        $this->table('audit_chain_state', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'integer', ['signed' => false])
            ->addColumn('head_hash', 'char', ['limit' => 64])
            ->addColumn('updated_at', 'datetime', ['precision' => 6])
            ->create();
        $this->execute(sprintf(
            "INSERT INTO audit_chain_state (id, head_hash, updated_at) VALUES (1, '%s', '%s')",
            str_repeat('0', 64),
            $now,
        ));

        $this->table('export_jobs', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('public_id', 'char', ['limit' => 36])
            ->addColumn('requested_by_user_id', 'biginteger', ['signed' => false])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'queued'])
            ->addColumn('mode', 'string', ['limit' => 16])
            ->addColumn('format', 'string', ['limit' => 16])
            ->addColumn('parameters_json', 'text', ['limit' => MysqlAdapter::TEXT_LONG])
            ->addColumn('draft', 'boolean', ['default' => false])
            ->addColumn('attempt_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('file_name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('file_path', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('mime_type', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('file_size', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('sha256', 'char', ['limit' => 64, 'null' => true])
            ->addColumn('audit_head_hash', 'char', ['limit' => 64, 'null' => true])
            ->addColumn('error_code', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('error_message', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('started_at', 'datetime', ['precision' => 6, 'null' => true])
            ->addColumn('completed_at', 'datetime', ['precision' => 6, 'null' => true])
            ->addColumn('expires_at', 'datetime', ['precision' => 6, 'null' => true])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addColumn('updated_at', 'datetime', ['precision' => 6])
            ->addIndex(['public_id'], ['unique' => true, 'name' => 'uq_export_jobs_public_id'])
            ->addIndex(['status', 'created_at'], ['name' => 'idx_export_jobs_status_created'])
            ->addIndex(['requested_by_user_id', 'created_at'], ['name' => 'idx_export_jobs_user_created'])
            ->addForeignKey('requested_by_user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        $this->table('device_transmissions')
            ->addColumn('diagnostic_errors_json', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG, 'after' => 'rejected_count'])
            ->update();
    }

    public function down(): void
    {
        $this->table('device_transmissions')->removeColumn('diagnostic_errors_json')->update();
        foreach ([
            'export_jobs', 'audit_chain_state', 'audit_log', 'event_verifications',
            'corrective_action_revisions', 'corrective_actions', 'compliance_events',
            'battery_cycles', 'measurement_point_compliance_configs', 'establishments',
            'login_attempts', 'user_sessions', 'users',
        ] as $table) {
            $this->table($table)->drop()->save();
        }
    }
}
