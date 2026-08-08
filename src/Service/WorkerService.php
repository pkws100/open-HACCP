<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Repository\AuthRepository;
use Haccp\Repository\ExportRepository;
use Haccp\Support\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class WorkerService
{
    public function __construct(
        private ExportRepository $exports,
        private ExportGenerator $generator,
        private ComplianceEventService $events,
        private AuthRepository $auth,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function tick(): bool
    {
        $now = $this->clock->database($this->clock->now());
        $this->cleanup($now);
        $this->events->reconcileOffline();
        $this->auth->purgeExpired($now);
        $job = $this->exports->claimNext($now);
        if ($job === null) {
            return false;
        }
        try {
            $file = $this->generator->generate($job);
            $this->exports->complete((int) $job['id'], $file);
            $this->logger->info('export_completed', ['export_id' => $job['public_id'], 'format' => $job['format'], 'file_size' => $file['file_size']]);
        } catch (Throwable $exception) {
            $code = str_starts_with($exception->getMessage(), 'EXPORT_TOO_LARGE') ? 'EXPORT_TOO_LARGE' : 'EXPORT_GENERATION_FAILED';
            $now = $this->clock->database($this->clock->now());
            $retryable = $code === 'EXPORT_GENERATION_FAILED' && (int) $job['attempt_count'] < 2;
            if ($retryable) {
                $this->exports->retry((int) $job['id'], $code, $exception->getMessage(), $now);
                $this->logger->warning('export_retry_scheduled', ['export_id' => $job['public_id'], 'error_code' => $code, 'attempt' => $job['attempt_count']]);
            } else {
                $this->exports->fail((int) $job['id'], $code, $exception->getMessage(), $now);
                $this->logger->error('export_failed', ['export_id' => $job['public_id'], 'error_code' => $code, 'exception' => $exception::class]);
            }
        }

        return true;
    }

    private function cleanup(string $now): void
    {
        foreach ($this->exports->expireDue($now) as $row) {
            $path = $row['file_path'];
            if (is_string($path) && $path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }
}
