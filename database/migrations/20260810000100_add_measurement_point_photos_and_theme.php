<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddMeasurementPointPhotosAndTheme extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('theme_preference', 'string', [
                'limit' => 16,
                'default' => 'system',
                'after' => 'role',
            ])
            ->update();

        $this->table('measurement_point_photos', [
            'id' => false,
            'primary_key' => ['id'],
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('public_id', 'char', ['limit' => 36])
            ->addColumn('measurement_point_id', 'biginteger', ['signed' => false])
            ->addColumn('revision', 'integer', ['signed' => false])
            ->addColumn('is_current', 'boolean', ['default' => true])
            ->addColumn('full_path', 'string', ['limit' => 500])
            ->addColumn('thumbnail_path', 'string', ['limit' => 500])
            ->addColumn('mime_type', 'string', ['limit' => 80, 'default' => 'image/webp'])
            ->addColumn('width', 'integer', ['signed' => false])
            ->addColumn('height', 'integer', ['signed' => false])
            ->addColumn('full_size', 'biginteger', ['signed' => false])
            ->addColumn('thumbnail_size', 'biginteger', ['signed' => false])
            ->addColumn('full_sha256', 'char', ['limit' => 64])
            ->addColumn('thumbnail_sha256', 'char', ['limit' => 64])
            ->addColumn('created_by_user_id', 'biginteger', ['signed' => false])
            ->addColumn('created_at', 'datetime', ['precision' => 6])
            ->addColumn('deleted_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('deleted_at', 'datetime', ['precision' => 6, 'null' => true])
            ->addIndex(['public_id'], ['unique' => true, 'name' => 'uq_measurement_point_photos_public_id'])
            ->addIndex(['measurement_point_id', 'revision'], ['unique' => true, 'name' => 'uq_measurement_point_photos_revision'])
            ->addIndex(['measurement_point_id', 'is_current', 'deleted_at'], ['name' => 'idx_measurement_point_photos_current'])
            ->addForeignKey('measurement_point_id', 'measurement_points', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();
    }

    public function down(): void
    {
        $this->table('measurement_point_photos')->drop()->save();
        $this->table('users')->removeColumn('theme_preference')->update();
    }
}
