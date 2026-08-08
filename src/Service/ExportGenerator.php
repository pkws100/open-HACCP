<?php

declare(strict_types=1);

namespace Haccp\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Haccp\Repository\ComplianceRepository;
use Haccp\Repository\ExportRepository;
use Haccp\Support\Clock;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use ZipArchive;

final readonly class ExportGenerator
{
    public function __construct(
        private ExportRepository $exports,
        private ComplianceRepository $compliance,
        private AuditService $audit,
        private Clock $clock,
        private string $exportPath,
    ) {
    }

    /** @return array<string, mixed> */
    public function generate(array $job): array
    {
        $parameters = json_decode((string) $job['parameters_json'], true, 512, JSON_THROW_ON_ERROR);
        $measurements = $this->exports->measurements($parameters);
        if (count($measurements) >= 500000) {
            throw new RuntimeException('EXPORT_TOO_LARGE: Export exceeds 500000 measurement rows.');
        }
        $deviations = $this->exports->deviations($parameters);
        $extendedFields = $parameters['extended_fields'] ?? [];
        $transmissions = $job['mode'] === 'extended'
            && array_intersect(['rssi', 'wifi_timing', 'firmware', 'transmissions'], $extendedFields) !== []
                ? $this->exports->transmissions($parameters)
                : [];
        $configurations = $job['mode'] === 'extended' && in_array('configuration', $extendedFields, true)
            ? $this->exports->configurations($parameters)
            : [];
        $deviceConfigurations = $job['mode'] === 'extended' && in_array('configuration', $extendedFields, true)
            ? $this->exports->deviceConfigurations($parameters)
            : [];
        $establishment = $this->compliance->establishment();
        $datasetHash = hash('sha256', json_encode([
            'parameters' => $parameters,
            'measurements' => $measurements,
            'deviations' => $deviations,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if (!is_dir($this->exportPath) && !mkdir($this->exportPath, 0770, true) && !is_dir($this->exportPath)) {
            throw new RuntimeException('Export directory could not be created.');
        }
        $extension = $job['format'] === 'csv' ? 'zip' : $job['format'];
        $fileName = sprintf('haccp-%s-%s.%s', $job['mode'], $job['public_id'], $extension);
        $filePath = $this->exportPath . '/' . $fileName;
        $context = [
            'job' => $job,
            'parameters' => $parameters,
            'establishment' => $establishment,
            'measurements' => $measurements,
            'deviations' => $deviations,
            'transmissions' => $transmissions,
            'configurations' => $configurations,
            'device_configurations' => $deviceConfigurations,
            'battery_forecasts' => $job['mode'] === 'extended' && in_array('battery_forecast', $parameters['extended_fields'] ?? [], true)
                ? $this->batteryForecasts($measurements)
                : [],
            'dataset_hash' => $datasetHash,
        ];
        match ($job['format']) {
            'pdf' => $this->pdf($filePath, $context),
            'xlsx' => $this->xlsx($filePath, $context),
            'csv' => $this->csvPackage($filePath, $context),
            default => throw new RuntimeException('Unsupported export format.'),
        };
        if (!is_file($filePath)) {
            throw new RuntimeException('Export file was not created.');
        }
        chmod($filePath, 0660);
        $now = $this->clock->now();
        $auditHead = $this->audit->append(
            'export.generated',
            (int) $job['requested_by_user_id'],
            'export_job',
            (string) $job['public_id'],
            ['format' => $job['format'], 'mode' => $job['mode'], 'dataset_hash' => $datasetHash],
        );

        return [
            'file_name' => $fileName,
            'file_path' => $filePath,
            'mime_type' => match ($job['format']) {
                'pdf' => 'application/pdf',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                default => 'application/zip',
            },
            'file_size' => filesize($filePath),
            'sha256' => hash_file('sha256', $filePath),
            'audit_head_hash' => $auditHead,
            'completed_at' => $this->clock->database($now),
            'expires_at' => $this->clock->database($now->modify('+24 hours')),
            'updated_at' => $this->clock->database($now),
        ];
    }

    private function pdf(string $path, array $context): void
    {
        $summary = $this->dailySummary($context['measurements']);
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $draft = (bool) $context['job']['draft'];
        $period = $this->e($context['parameters']['from']) . ' bis ' . $this->e($context['parameters']['to']);
        $company = $this->e((string) ($context['establishment']['legal_name'] ?: 'Betrieb nicht vollständig hinterlegt'));
        $issues = $context['parameters']['preflight']['issues'] ?? [];
        $html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><style>
            @page { margin: 23mm 16mm 18mm; } body { font: 10px DejaVu Sans, sans-serif; color:#162523; }
            .footer { position: fixed; bottom:-12mm; left:0; right:0; color:#647875; font-size:8px; text-align:center; }
            h1 { font-size:23px; margin:0 0 4px; color:#173f3a; } h2 { font-size:14px; margin:20px 0 7px; color:#173f3a; border-bottom:1px solid #b9cfcb; padding-bottom:4px; }
            .eyebrow { color:#2d7c72; font-size:9px; letter-spacing:1.2px; text-transform:uppercase; }
            .meta { width:100%; margin:12px 0; border-collapse:collapse; } .meta td { padding:4px 8px 4px 0; vertical-align:top; }
            table.data { width:100%; border-collapse:collapse; font-size:8px; } table.data th { background:#173f3a; color:white; text-align:left; padding:5px; }
            table.data td { border-bottom:1px solid #d9e5e2; padding:5px; vertical-align:top; } .muted { color:#647875; }
            .notice { padding:8px 10px; background:#fff2d8; border-left:3px solid #c48620; margin:10px 0; }
            .ok { padding:8px 10px; background:#e6f3f0; border-left:3px solid #2d7c72; margin:10px 0; }
            .watermark { position:fixed; top:44%; left:8%; transform:rotate(-24deg); font-size:52px; color:rgba(180,105,30,.13); z-index:-1; }
            .bar { display:inline-block; height:7px; background:#2d7c72; min-width:2px; }
        </style></head><body>';
        if ($draft) {
            $html .= '<div class="watermark">ENTWURF - NACHWEIS UNVOLLSTÄNDIG</div>';
        }
        $html .= '<div class="footer">Open HACCP · Bericht ' . $this->e($context['job']['public_id']) . ' · </div>';
        $html .= '<div class="eyebrow">Open HACCP Monitor · Behörden-Nachweis</div><h1>Temperatur- und Abweichungsnachweis</h1>';
        $html .= '<table class="meta"><tr><td><strong>Betrieb</strong><br>' . $company . '<br>' . $this->e((string) ($context['establishment']['address_line1'] ?? '')) . '<br>' . $this->e(trim((string) ($context['establishment']['postal_code'] ?? '') . ' ' . (string) ($context['establishment']['city'] ?? ''))) . '</td>';
        $html .= '<td><strong>Zeitraum</strong><br>' . $period . '<br><strong>Zeitzone</strong><br>' . $this->e((string) ($context['establishment']['timezone'] ?? 'Europe/Berlin')) . '</td>';
        $html .= '<td><strong>Berichts-ID</strong><br>' . $this->e($context['job']['public_id']) . '<br><strong>Datensatz-Fingerabdruck</strong><br><span class="muted">' . $this->e(substr($context['dataset_hash'], 0, 24)) . '…</span></td></tr></table>';
        $html .= $draft ? '<div class="notice"><strong>Entwurf:</strong> ' . $this->e(implode(' · ', $issues)) . '</div>' : '<div class="ok">Der konfigurationsbezogene Preflight war zum Erzeugungszeitpunkt vollständig.</div>';
        $html .= '<h2>Umfang und Vollständigkeit</h2><p>' . count($context['measurements']) . ' Messwerte und ' . count($context['deviations']) . ' dokumentierte Abweichungen. Der PDF-Bericht fasst die Messreihe zusammen; vollständige Einzelwerte stehen im XLSX- oder CSV-Export bereit.</p>';
        if ($context['job']['mode'] === 'extended') {
            $fields = $context['parameters']['extended_fields'] ?? [];
            $html .= '<p class="muted">Ausgewählte technische Zusatzfelder: ' . $this->e($fields === [] ? 'keine' : implode(', ', $fields)) . '.</p>';
            foreach ($context['battery_forecasts'] as $forecast) {
                $html .= '<p class="muted">Batterieprognose ' . $this->e($forecast['device_uid']) . ': ' . $this->e($forecast['label']) . '.</p>';
            }
        }
        $html .= '<table class="data"><thead><tr><th>Tag</th><th>Gerät / Messstelle</th><th>Werte</th><th>Min.</th><th>Ø</th><th>Max.</th><th>Außerhalb</th><th>Verlauf</th></tr></thead><tbody>';
        $maxCount = max(1, ...array_column($summary ?: [['count' => 1]], 'count'));
        foreach ($summary as $row) {
            $width = max(2, (int) round(((int) $row['count'] / $maxCount) * 75));
            $html .= '<tr><td>' . $this->e($row['day']) . '</td><td>' . $this->e($row['label']) . '</td><td>' . $row['count'] . '</td><td>' . $this->n($row['min']) . ' °C</td><td>' . $this->n($row['avg']) . ' °C</td><td>' . $this->n($row['max']) . ' °C</td><td>' . $row['outside'] . '</td><td><span class="bar" style="width:' . $width . 'px"></span></td></tr>';
        }
        if ($summary === []) {
            $html .= '<tr><td colspan="8">Im gewählten Zeitraum liegen keine Messwerte vor.</td></tr>';
        }
        $html .= '</tbody></table><h2>Abweichungen und Korrekturmaßnahmen</h2><table class="data"><thead><tr><th>Beginn</th><th>Gerät / Messstelle</th><th>Art</th><th>Status</th><th>Maßnahme / Warenentscheidung</th><th>Prüfung</th></tr></thead><tbody>';
        foreach ($context['deviations'] as $row) {
            $html .= '<tr><td>' . $this->e((string) $row['opened_at']) . '</td><td>' . $this->e(trim($row['device_name'] . ' / ' . ($row['point_name'] ?? 'Gerät'))) . '</td><td>' . $this->e($this->eventLabel((string) $row['event_type'])) . '</td><td>' . $this->e((string) $row['state']) . '</td><td>' . $this->e(trim((string) ($row['action_taken'] ?? '') . ' / ' . (string) ($row['product_disposition'] ?? ''))) . '</td><td>' . $this->e(trim((string) ($row['verified_by'] ?? '') . ' ' . (string) ($row['verified_at'] ?? ''))) . '</td></tr>';
        }
        if ($context['deviations'] === []) {
            $html .= '<tr><td colspan="6">Keine Abweichungen im gewählten Zeitraum.</td></tr>';
        }
        $html .= '</tbody></table><h2>Nachweisangaben</h2><p class="muted">Rechtsprofil: ' . $this->e((string) ($context['parameters']['legal_profile'] ?? 'configured')) . '. Dieser Bericht ist keine Rechtsberatung oder Zertifizierung.</p></body></html>';
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->getCanvas()->page_text(474, 816, 'Seite {PAGE_NUM} von {PAGE_COUNT}', 'Helvetica', 8, [0.39, 0.47, 0.46]);
        if (file_put_contents($path, $dompdf->output()) === false) {
            throw new RuntimeException('PDF export could not be written.');
        }
    }

    private function xlsx(string $path, array $context): void
    {
        $writer = new Writer();
        $writer->openToFile($path);
        $header = (new Style())->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('173F3A')->setShouldWrapText();
        $title = (new Style())->setFontBold()->setFontSize(15)->setFontColor('173F3A');
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Nachweis');
        $sheet->setColumnWidth(24, 1, 2);
        $writer->addRow(Row::fromValues(['Open HACCP Behörden-Nachweis'], $title));
        $manifest = [
            ['Berichts-ID', $context['job']['public_id']],
            ['Status', $context['job']['draft'] ? 'Entwurf / Nachweis unvollständig' : 'Preflight vollständig'],
            ['Betrieb', $context['establishment']['legal_name'] ?? ''],
            ['Rechtsprofil', $context['parameters']['legal_profile'] ?? 'configured'],
            ['Zeitraum von', $context['parameters']['from']],
            ['Zeitraum bis', $context['parameters']['to']],
            ['Messwerte', count($context['measurements'])],
            ['Abweichungen', count($context['deviations'])],
            ['Technische Zusatzfelder', implode(', ', $context['parameters']['extended_fields'] ?? [])],
            ['Batterieprognosen', json_encode($context['battery_forecasts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ['Datensatz-SHA-256', $context['dataset_hash']],
            ['Rechtsgrundlage', 'https://eur-lex.europa.eu/eli/reg/2004/852/oj/'],
            ['Hinweis Tiefkühlung', 'https://eur-lex.europa.eu/eli/reg/2005/37/oj/deu'],
        ];
        foreach ($manifest as $row) {
            $writer->addRow(Row::fromValues(array_map($this->safe(...), $row)));
        }

        $measurementHeaders = [
            'Zeitpunkt UTC', 'Geräte-UID', 'Gerät', 'Messstelle', 'Ort', 'Klassifizierung', 'Zweck',
            'Temperatur °C', 'Minimum °C', 'Maximum °C', 'Status', 'Messintervall s',
        ];
        if ($this->selected($context, 'humidity')) $measurementHeaders[] = 'Feuchte % rF';
        if ($this->selected($context, 'battery')) $measurementHeaders[] = 'Batterie mV';
        if ($this->selected($context, 'sequences')) $measurementHeaders[] = 'Sequenz';
        if ($this->selected($context, 'received_at')) $measurementHeaders[] = 'Empfangen UTC';
        if ($this->selected($context, 'firmware')) array_push($measurementHeaders, 'Firmware', 'Hardware');
        $this->newSheet($writer, 'Messwerte', $measurementHeaders, array_map(function (array $row) use ($context): array {
            $base = [
                new \DateTimeImmutable((string) $row['measured_at'], new \DateTimeZone('UTC')),
                $this->safe($row['device_uid']), $this->safe($row['device_name']), $this->safe($row['point_name']),
                $this->safe($row['location']), $this->safe($row['control_classification']), $this->safe($row['monitoring_purpose']),
                (float) $row['temperature_c'], $row['temperature_min_c'] === null ? null : (float) $row['temperature_min_c'],
                $row['temperature_max_c'] === null ? null : (float) $row['temperature_max_c'], $this->measurementStatus($row),
                $this->effectiveMeasurementInterval($row),
            ];
            if ($this->selected($context, 'humidity')) $base[] = (float) $row['humidity_rh'];
            if ($this->selected($context, 'battery')) $base[] = (int) $row['battery_mv'];
            if ($this->selected($context, 'sequences')) $base[] = (int) $row['sequence'];
            if ($this->selected($context, 'received_at')) $base[] = new \DateTimeImmutable((string) $row['received_at'], new \DateTimeZone('UTC'));
            if ($this->selected($context, 'firmware')) {
                $base[] = $this->safe($row['firmware_version']);
                $base[] = $this->safe($row['hardware_revision']);
            }
            return $base;
        }, $context['measurements']), $header);

        $this->newSheet($writer, 'Abweichungen', ['Beginn UTC', 'Ende UTC', 'Gerät', 'Messstelle', 'Art', 'Schwere', 'Status', 'Bestätigt von', 'Ursache', 'Maßnahme', 'Warenentscheidung', 'Verantwortlich', 'Geprüft von', 'Geprüft UTC'], array_map(fn (array $row): array => [
            new \DateTimeImmutable((string) $row['opened_at'], new \DateTimeZone('UTC')),
            $row['closed_at'] === null ? null : new \DateTimeImmutable((string) $row['closed_at'], new \DateTimeZone('UTC')),
            $this->safe($row['device_name']), $this->safe($row['point_name']), $this->safe($this->eventLabel($row['event_type'])),
            $this->safe($row['severity']), $this->safe($row['state']), $this->safe($row['acknowledged_by']),
            $this->safe($row['cause']), $this->safe($row['action_taken']), $this->safe($row['product_disposition']),
            $this->safe($row['responsible_name']), $this->safe($row['verified_by']), $row['verified_at'] === null ? null : new \DateTimeImmutable((string) $row['verified_at'], new \DateTimeZone('UTC')),
        ], $context['deviations']), $header);

        $quality = array_values(array_filter($context['deviations'], static fn (array $row): bool => in_array($row['event_type'], ['sequence_gap', 'measurement_rejected', 'device_offline'], true)));
        $this->newSheet($writer, 'Datenqualität', ['Zeitpunkt UTC', 'Gerät', 'Messstelle', 'Art', 'Status'], array_map(fn (array $row): array => [
            new \DateTimeImmutable((string) $row['opened_at'], new \DateTimeZone('UTC')), $this->safe($row['device_name']),
            $this->safe($row['point_name']), $this->safe($this->eventLabel($row['event_type'])), $this->safe($row['state']),
        ], $quality), $header);

        if ($context['job']['mode'] === 'extended') {
            $diagnosticHeaders = ['Zeitpunkt UTC', 'Gerät'];
            if ($this->selected($context, 'battery')) $diagnosticHeaders[] = 'Batterie mV';
            if ($this->selected($context, 'rssi')) $diagnosticHeaders[] = 'RSSI dBm';
            if ($this->selected($context, 'wifi_timing')) $diagnosticHeaders[] = 'WLAN ms';
            if ($this->selected($context, 'firmware')) array_push($diagnosticHeaders, 'Firmware', 'Hardware');
            if ($this->selected($context, 'transmissions')) array_push($diagnosticHeaders, 'Boots', 'Diagnosecodes');
            $diagnosticRows = array_map(function (array $row) use ($context): array {
                $values = [new \DateTimeImmutable((string) $row['received_at'], new \DateTimeZone('UTC')), $this->safe($row['device_name'])];
                if ($this->selected($context, 'battery')) $values[] = (int) $row['battery_mv'];
                if ($this->selected($context, 'rssi')) $values[] = (int) $row['rssi_dbm'];
                if ($this->selected($context, 'wifi_timing')) $values[] = (int) $row['wifi_connect_ms'];
                if ($this->selected($context, 'firmware')) array_push($values, $this->safe($row['firmware_version']), $this->safe($row['hardware_revision']));
                if ($this->selected($context, 'transmissions')) array_push($values, (int) $row['boot_count'], $this->safe($row['diagnostic_errors_json']));
                return $values;
            }, $context['transmissions']);
            $this->newSheet($writer, 'Diagnose', $diagnosticHeaders, $diagnosticRows, $header);

            $transmissionRows = $this->selected($context, 'transmissions') ? array_map(fn (array $row): array => [
                new \DateTimeImmutable((string) $row['received_at'], new \DateTimeZone('UTC')), $this->safe($row['device_name']),
                $this->safe($row['transmission_type']), (int) $row['measurement_count'],
                (int) $row['accepted_count'], (int) $row['duplicate_count'], (int) $row['rejected_count'],
            ], $context['transmissions']) : [['Nicht ausgewählt', null, null, null, null, null, null]];
            $this->newSheet($writer, 'Übertragungen', ['Zeitpunkt UTC', 'Gerät', 'Typ', 'Messwerte', 'Akzeptiert', 'Duplikate', 'Abgelehnt'], $transmissionRows, $header);

            $configurationRows = $this->selected($context, 'configuration') ? array_map(fn (array $row): array => [
                'Messstellen-Compliance', $this->safe($row['device_name']), $this->safe($row['point_name']), (int) $row['config_version'],
                new \DateTimeImmutable((string) $row['effective_from'], new \DateTimeZone('UTC')), $this->safe($row['legal_profile']),
                $this->safe($row['control_classification']), $this->safe($row['monitoring_purpose']), (int) $row['retention_months'],
                $this->safe($row['responsible_name']), $this->safe($row['instrument_manufacturer']), $this->safe($row['instrument_model']),
                $this->safe($row['instrument_serial']), $this->safe($row['conformity_status']), $this->safe($row['conformity_reference']),
                $this->safe($row['calibration_reference']), $this->safe($row['verification_reference']),
                $this->safe($row['calibrated_at']), $this->safe($row['verification_due_at']),
                null, null, null, null, null, null, null, null,
            ], $context['configurations']) : [];
            if ($this->selected($context, 'configuration')) {
                foreach ($context['device_configurations'] as $row) {
                    $configurationRows[] = [
                        'Gerätekonfiguration', $this->safe($row['device_name']), null, (int) $row['config_version'],
                        new \DateTimeImmutable((string) $row['effective_from'], new \DateTimeZone('UTC')),
                        null, null, null, null, null, null, null, null, null, null, null, null, null, null,
                        (int) $row['measurement_interval_seconds'], (int) $row['upload_interval_seconds'],
                        (bool) $row['alarm_enabled'] ? 'aktiv' : 'deaktiviert',
                        $row['temperature_min_c'] === null ? null : (float) $row['temperature_min_c'],
                        $row['temperature_max_c'] === null ? null : (float) $row['temperature_max_c'],
                        (int) $row['battery_low_mv'], (int) $row['battery_full_mv'], $this->safe($row['config_json']),
                    ];
                }
            }
            if ($configurationRows === []) {
                $configurationRows[] = ['Nicht ausgewählt'];
            }
            $this->newSheet($writer, 'Konfiguration', ['Typ', 'Gerät', 'Messstelle', 'Version', 'Wirksam ab UTC', 'Rechtsprofil', 'Klassifizierung', 'Zweck', 'Aufbewahrung Monate', 'Verantwortlich', 'Hersteller', 'Modell', 'Seriennummer', 'Konformitätsstatus', 'Konformitätsreferenz', 'Kalibrierungsreferenz', 'Prüfreferenz', 'Kalibriert', 'Prüfung fällig', 'Messintervall s', 'Uploadintervall s', 'Alarm', 'Minimum °C', 'Maximum °C', 'Batterie niedrig mV', 'Batterie voll mV', 'Messstellenintervalle JSON'], $configurationRows, $header);
        }
        $writer->close();
    }

    private function newSheet(Writer $writer, string $name, array $headers, array $rows, Style $header): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);
        $sheet->setColumnWidthForRange(18, 1, max(1, count($headers)));
        $sheet->setColumnWidth(24, 1, 2, 3, 4);
        $writer->addRow(Row::fromValues($headers, $header));
        $dateStyle = (new Style())->setFormat('yyyy-mm-dd hh:mm:ss');
        foreach ($rows as $row) {
            $columnStyles = [];
            foreach ($row as $index => $value) {
                if ($value instanceof \DateTimeInterface) {
                    $columnStyles[$index] = $dateStyle;
                }
            }
            $writer->addRow(Row::fromValuesWithStyles($row, null, $columnStyles));
        }
    }

    private function csvPackage(string $path, array $context): void
    {
        $tempDir = sys_get_temp_dir() . '/haccp-export-' . bin2hex(random_bytes(8));
        if (!mkdir($tempDir, 0700, true)) {
            throw new RuntimeException('CSV temporary directory could not be created.');
        }
        try {
            $this->writeCsv($tempDir . '/manifest.csv', ['Feld', 'Wert'], [
                ['Berichts-ID', $context['job']['public_id']],
                ['Status', $context['job']['draft'] ? 'Entwurf / Nachweis unvollständig' : 'Preflight vollständig'],
                ['Betrieb', $context['establishment']['legal_name'] ?? ''],
                ['Rechtsprofil', $context['parameters']['legal_profile'] ?? 'configured'],
                ['Von UTC', $context['parameters']['from']], ['Bis UTC', $context['parameters']['to']],
                ['Technische Zusatzfelder', implode(', ', $context['parameters']['extended_fields'] ?? [])],
                ['Batterieprognosen', json_encode($context['battery_forecasts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ['Datensatz-SHA-256', $context['dataset_hash']],
            ]);
            $measurementHeaders = ['measured_at_utc', 'device_uid', 'device_name', 'point_code', 'point_name', 'location', 'classification', 'purpose', 'temperature_c', 'temperature_min_c', 'temperature_max_c', 'status', 'measurement_interval_seconds'];
            if ($this->selected($context, 'humidity')) $measurementHeaders[] = 'humidity_rh';
            if ($this->selected($context, 'battery')) $measurementHeaders[] = 'battery_mv';
            if ($this->selected($context, 'sequences')) $measurementHeaders[] = 'sequence';
            if ($this->selected($context, 'received_at')) $measurementHeaders[] = 'received_at_utc';
            if ($this->selected($context, 'firmware')) array_push($measurementHeaders, 'firmware_version', 'hardware_revision');
            $measurementRows = [];
            foreach ($context['measurements'] as $row) {
                $values = [
                    $row['measured_at'], $row['device_uid'], $row['device_name'], $row['point_code'], $row['point_name'],
                    $row['location'], $row['control_classification'], $row['monitoring_purpose'], (float) $row['temperature_c'],
                    $row['temperature_min_c'] === null ? null : (float) $row['temperature_min_c'],
                    $row['temperature_max_c'] === null ? null : (float) $row['temperature_max_c'],
                    $this->measurementStatus($row), $this->effectiveMeasurementInterval($row),
                ];
                if ($this->selected($context, 'humidity')) $values[] = (float) $row['humidity_rh'];
                if ($this->selected($context, 'battery')) $values[] = (int) $row['battery_mv'];
                if ($this->selected($context, 'sequences')) $values[] = (int) $row['sequence'];
                if ($this->selected($context, 'received_at')) $values[] = $row['received_at'];
                if ($this->selected($context, 'firmware')) array_push($values, $row['firmware_version'], $row['hardware_revision']);
                $measurementRows[] = $values;
            }
            $this->writeCsv($tempDir . '/measurements.csv', $measurementHeaders, $measurementRows);
            $this->writeCsv($tempDir . '/deviations.csv', ['opened_at_utc', 'closed_at_utc', 'device_uid', 'point_code', 'event_type', 'severity', 'state', 'acknowledged_by', 'cause', 'action_taken', 'product_disposition', 'responsible_name', 'verified_by', 'verified_at_utc'], array_map(static fn (array $row): array => [
                $row['opened_at'], $row['closed_at'], $row['device_uid'], $row['point_code'], $row['event_type'],
                $row['severity'], $row['state'], $row['acknowledged_by'], $row['cause'], $row['action_taken'],
                $row['product_disposition'], $row['responsible_name'], $row['verified_by'], $row['verified_at'],
            ], $context['deviations']));
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('CSV ZIP package could not be opened.');
            }
            foreach (['manifest.csv', 'measurements.csv', 'deviations.csv'] as $file) {
                $zip->addFile($tempDir . '/' . $file, $file);
            }
            $zip->close();
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($tempDir);
        }
    }

    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('CSV file could not be opened.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map($this->safe(...), $row), ';', '"', '');
        }
        fclose($handle);
    }

    /** @return list<array{day:string,label:string,count:int,min:float,avg:float,max:float,outside:int}> */
    private function dailySummary(array $measurements): array
    {
        $groups = [];
        foreach ($measurements as $row) {
            $key = substr((string) $row['measured_at'], 0, 10) . '|' . $row['device_uid'] . '|' . $row['point_code'];
            $groups[$key] ??= ['day' => substr((string) $row['measured_at'], 0, 10), 'label' => $row['device_name'] . ' / ' . $row['point_name'], 'values' => [], 'outside' => 0];
            $groups[$key]['values'][] = (float) $row['temperature_c'];
            if ($this->measurementStatus($row) !== 'normal') {
                $groups[$key]['outside']++;
            }
        }

        return array_values(array_map(static fn (array $group): array => [
            'day' => $group['day'], 'label' => $group['label'], 'count' => count($group['values']),
            'min' => min($group['values']), 'avg' => array_sum($group['values']) / count($group['values']),
            'max' => max($group['values']), 'outside' => $group['outside'],
        ], $groups));
    }

    private function measurementStatus(array $row): string
    {
        if ($row['temperature_min_c'] !== null && (float) $row['temperature_c'] < (float) $row['temperature_min_c']) {
            return 'below_min';
        }
        if ($row['temperature_max_c'] !== null && (float) $row['temperature_c'] > (float) $row['temperature_max_c']) {
            return 'above_max';
        }

        return 'normal';
    }

    /** @return list<array{device_uid:string,status:string,estimated_days_remaining:int|null,confidence:string|null,label:string}> */
    private function batteryForecasts(array $measurements): array
    {
        $groups = [];
        foreach ($measurements as $row) {
            $groups[(string) $row['device_uid']][] = $row;
        }
        $result = [];
        foreach ($groups as $deviceUid => $rows) {
            usort($rows, static fn (array $left, array $right): int => strcmp((string) $left['measured_at'], (string) $right['measured_at']));
            $latestTimestamp = strtotime((string) end($rows)['measured_at']);
            $rows = array_values(array_filter($rows, static fn (array $row): bool => strtotime((string) $row['measured_at']) >= $latestTimestamp - 30 * 86400));
            $firstTimestamp = strtotime((string) $rows[0]['measured_at']);
            $spanDays = ($latestTimestamp - $firstTimestamp) / 86400;
            if (count($rows) < 20 || $spanDays < 7) {
                $result[] = ['device_uid' => $deviceUid, 'status' => 'insufficient_data', 'estimated_days_remaining' => null, 'confidence' => null, 'label' => 'noch keine belastbare Prognose'];
                continue;
            }
            $xs = array_map(static fn (array $row): float => (strtotime((string) $row['measured_at']) - $firstTimestamp) / 86400, $rows);
            $ys = array_map(static fn (array $row): int => (int) $row['battery_mv'], $rows);
            $meanX = array_sum($xs) / count($xs);
            $meanY = array_sum($ys) / count($ys);
            $numerator = 0.0;
            $denominator = 0.0;
            foreach ($xs as $index => $x) {
                $numerator += ($x - $meanX) * ($ys[$index] - $meanY);
                $denominator += ($x - $meanX) ** 2;
            }
            $slope = $denominator === 0.0 ? 0.0 : $numerator / $denominator;
            if ($slope >= -0.1) {
                $result[] = ['device_uid' => $deviceUid, 'status' => 'non_declining', 'estimated_days_remaining' => null, 'confidence' => null, 'label' => 'noch keine belastbare Prognose'];
                continue;
            }
            $intercept = $meanY - $slope * $meanX;
            $ssResidual = 0.0;
            $ssTotal = 0.0;
            foreach ($xs as $index => $x) {
                $ssResidual += ($ys[$index] - ($intercept + $slope * $x)) ** 2;
                $ssTotal += ($ys[$index] - $meanY) ** 2;
            }
            $r2 = $ssTotal === 0.0 ? 0.0 : max(0.0, 1 - $ssResidual / $ssTotal);
            $low = (int) (end($rows)['battery_low_mv'] ?? 5600);
            $days = max(0, min(730, (int) round(((int) end($ys) - $low) / abs($slope))));
            $confidence = $r2 >= 0.75 && $spanDays >= 21 && count($rows) >= 100 ? 'high' : ($r2 >= 0.45 && $spanDays >= 14 ? 'medium' : 'low');
            $result[] = ['device_uid' => $deviceUid, 'status' => 'estimated', 'estimated_days_remaining' => $days, 'confidence' => $confidence, 'label' => sprintf('ca. %d Tage (%s)', $days, $confidence)];
        }

        return $result;
    }

    private function safe(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }

    private function selected(array $context, string $field): bool
    {
        return $context['job']['mode'] === 'extended'
            && in_array($field, $context['parameters']['extended_fields'] ?? [], true);
    }

    private function effectiveMeasurementInterval(array $row): ?int
    {
        $default = $row['measurement_interval_seconds'] === null ? null : (int) $row['measurement_interval_seconds'];
        if (empty($row['historical_config_json'])) {
            return $default;
        }
        $config = json_decode((string) $row['historical_config_json'], true);
        $interval = $config['measurement_point_intervals'][$row['point_code']] ?? null;

        return is_int($interval) ? $interval : $default;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function n(float|int $value): string
    {
        return number_format((float) $value, 1, ',', '.');
    }

    private function eventLabel(string $type): string
    {
        return match ($type) {
            'temperature_below_min' => 'Temperatur unter Minimum',
            'temperature_above_max' => 'Temperatur über Maximum',
            'device_offline' => 'Gerät offline',
            'battery_low' => 'Batterie niedrig',
            'signal_weak' => 'Funksignal schwach',
            'measurement_rejected' => 'Messung abgelehnt',
            'sequence_gap' => 'Sequenzlücke',
            'firmware_diagnostic' => 'Firmware-Diagnose',
            default => $type,
        };
    }
}
