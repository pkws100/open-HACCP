<?php

declare(strict_types=1);

namespace Haccp\Repository;

use PDO;

final readonly class AnalysisRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function measurements(string $from, string $to, ?string $deviceUid, ?int $pointId): array
    {
        $where = ['m.measured_at >= :from', 'm.measured_at <= :to', "d.status = 'active'"];
        $params = ['from' => $from, 'to' => $to];
        if ($deviceUid !== null) {
            $where[] = 'd.device_uid = :device_uid';
            $params['device_uid'] = $deviceUid;
        }
        if ($pointId !== null) {
            $where[] = 'm.measurement_point_id = :point_id';
            $params['point_id'] = $pointId;
        }
        $statement = $this->pdo->prepare(
            'SELECT m.measured_at, m.temperature_c, m.humidity_rh, m.battery_mv,
                    m.measurement_point_id, d.id AS device_id, d.device_uid, d.name AS device_name,
                    mp.name AS point_name, mp.code AS point_code
             FROM measurements m INNER JOIN devices d ON d.id = m.device_id
             INNER JOIN measurement_points mp ON mp.id = m.measurement_point_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY m.measured_at ASC LIMIT 30000',
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function transmissionDaily(string $from, string $to, ?string $deviceUid): array
    {
        $where = ['t.received_at >= :from', 't.received_at <= :to', "d.status = 'active'"];
        $params = ['from' => $from, 'to' => $to];
        if ($deviceUid !== null) {
            $where[] = 'd.device_uid = :device_uid';
            $params['device_uid'] = $deviceUid;
        }
        $statement = $this->pdo->prepare(
            'SELECT DATE(t.received_at) AS day, COUNT(*) AS transmissions,
                    AVG(t.rssi_dbm) AS average_rssi_dbm, AVG(t.wifi_connect_ms) AS average_wifi_connect_ms,
                    SUM(t.rejected_count) AS rejected_measurements
             FROM device_transmissions t INNER JOIN devices d ON d.id = t.device_id
             WHERE ' . implode(' AND ', $where) . ' GROUP BY DATE(t.received_at) ORDER BY day',
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function eventDaily(string $from, string $to, ?string $deviceUid): array
    {
        $where = ['e.opened_at >= :from', 'e.opened_at <= :to', "d.status = 'active'"];
        $params = ['from' => $from, 'to' => $to];
        if ($deviceUid !== null) {
            $where[] = 'd.device_uid = :device_uid';
            $params['device_uid'] = $deviceUid;
        }
        $statement = $this->pdo->prepare(
            'SELECT DATE(e.opened_at) AS day, e.event_type, e.severity, COUNT(*) AS event_count
             FROM compliance_events e INNER JOIN devices d ON d.id = e.device_id
             WHERE ' . implode(' AND ', $where) . ' GROUP BY DATE(e.opened_at), e.event_type, e.severity
             ORDER BY day, e.event_type',
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function availability(string $from, string $to, ?string $deviceUid): array
    {
        $where = ["d.status = 'active'"];
        $params = [
            'from_transmission' => $from,
            'to_transmission' => $to,
            'from_expected' => $from,
            'to_expected' => $to,
        ];
        if ($deviceUid !== null) {
            $where[] = 'd.device_uid = :device_uid';
            $params['device_uid'] = $deviceUid;
        }
        $statement = $this->pdo->prepare(
            'SELECT d.device_uid, d.name, dc.upload_interval_seconds, d.last_seen_at,
                    COUNT(t.id) AS transmissions,
                    GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, GREATEST(:from_expected, d.created_at), :to_expected) / dc.upload_interval_seconds)) AS expected_transmissions
             FROM devices d INNER JOIN device_configs dc ON dc.id = (
                 SELECT latest.id FROM device_configs latest WHERE latest.device_id = d.id ORDER BY latest.config_version DESC LIMIT 1
             ) LEFT JOIN device_transmissions t ON t.device_id = d.id
                AND t.received_at >= :from_transmission AND t.received_at <= :to_transmission
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY d.id, d.device_uid, d.name, dc.upload_interval_seconds, d.last_seen_at ORDER BY d.name',
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function fleetKpis(string $from, string $to, ?string $deviceUid, ?int $pointId): array
    {
        $deviceWhere = ["d.status = 'active'"];
        $eventWhere = ["e.state <> 'resolved'", "d.status = 'active'"];
        $measurementWhere = ['m.measured_at >= :measurement_from', 'm.measured_at <= :measurement_to', "d.status = 'active'"];
        $transmissionWhere = ['t.received_at >= :transmission_from', 't.received_at <= :transmission_to', "d.status = 'active'"];
        $params = [
            'measurement_from' => $from,
            'measurement_to' => $to,
            'transmission_from' => $from,
            'transmission_to' => $to,
        ];
        if ($deviceUid !== null) {
            $deviceWhere[] = 'd.device_uid = :device_uid';
            $eventWhere[] = 'd.device_uid = :event_device_uid';
            $measurementWhere[] = 'd.device_uid = :measurement_device_uid';
            $transmissionWhere[] = 'd.device_uid = :transmission_device_uid';
            $params['device_uid'] = $deviceUid;
            $params['event_device_uid'] = $deviceUid;
            $params['measurement_device_uid'] = $deviceUid;
            $params['transmission_device_uid'] = $deviceUid;
        }
        if ($pointId !== null) {
            $deviceWhere[] = 'EXISTS (SELECT 1 FROM measurement_points dp WHERE dp.device_id = d.id AND dp.id = :device_point_id AND dp.active = 1)';
            $eventWhere[] = 'e.measurement_point_id = :event_point_id';
            $measurementWhere[] = 'm.measurement_point_id = :measurement_point_id';
            $transmissionWhere[] = 'EXISTS (SELECT 1 FROM measurement_points tp WHERE tp.device_id = d.id AND tp.id = :transmission_point_id AND tp.active = 1)';
            $params['device_point_id'] = $pointId;
            $params['event_point_id'] = $pointId;
            $params['measurement_point_id'] = $pointId;
            $params['transmission_point_id'] = $pointId;
        }
        $statement = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM devices d WHERE ' . implode(' AND ', $deviceWhere) . ') AS devices,
                    (SELECT COUNT(*) FROM compliance_events e INNER JOIN devices d ON d.id = e.device_id WHERE ' . implode(' AND ', $eventWhere) . ') AS open_events,
                    (SELECT COUNT(*) FROM measurements m INNER JOIN devices d ON d.id = m.device_id WHERE ' . implode(' AND ', $measurementWhere) . ') AS measurements,
                    (SELECT COALESCE(SUM(t.rejected_count), 0) FROM device_transmissions t INNER JOIN devices d ON d.id = t.device_id WHERE ' . implode(' AND ', $transmissionWhere) . ') AS rejections',
        );
        $statement->execute($params);

        return $statement->fetch();
    }
}
