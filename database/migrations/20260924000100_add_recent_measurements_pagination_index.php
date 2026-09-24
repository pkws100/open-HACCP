<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddRecentMeasurementsPaginationIndex extends AbstractMigration
{
    public function up(): void
    {
        $this->table('measurements')
            ->addIndex(
                ['measurement_point_id', 'measured_at', 'sequence'],
                ['name' => 'idx_measurements_point_recent'],
            )
            ->update();
    }

    public function down(): void
    {
        $this->table('measurements')
            ->removeIndexByName('idx_measurements_point_recent')
            ->update();
    }
}
