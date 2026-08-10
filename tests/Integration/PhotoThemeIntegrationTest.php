<?php

declare(strict_types=1);

namespace Haccp\Tests\Integration;

use Imagick;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

final class PhotoThemeIntegrationTest extends IntegrationTestCase
{
    public function testThemePreferenceIsValidatedAndPersistsWithTheUser(): void
    {
        $response = $this->dashboardRequest('/api/v1/auth/me/preferences', true, 'PUT', ['theme' => 'light']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('light', $this->json($response)['preferences']['theme_preference']);

        $me = $this->json($this->dashboardRequest('/api/v1/auth/me'));
        self::assertSame('light', $me['user']['theme_preference']);
        self::assertSame('light', $this->pdo->query('SELECT theme_preference FROM users LIMIT 1')->fetchColumn());

        $invalid = $this->dashboardRequest('/api/v1/auth/me/preferences', true, 'PUT', ['theme' => 'sepia']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame('INVALID_THEME_PREFERENCE', $this->json($invalid)['error']['code']);
    }

    public function testPhotoUploadCreatesSafeVariantsHistoryAndOverviewThumbnail(): void
    {
        $pointId = $this->pointId();
        $first = $this->uploadPhoto($pointId, $this->jpeg('first', 1200, 900));
        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());
        $firstPhoto = $this->json($first)['photo'];
        self::assertSame(1, $firstPhoto['revision']);
        self::assertTrue($firstPhoto['is_current']);
        self::assertSame(1200, $firstPhoto['width']);
        self::assertSame(900, $firstPhoto['height']);

        $stored = $this->pdo->query('SELECT * FROM measurement_point_photos WHERE revision = 1')->fetch();
        self::assertSame('image/webp', $stored['mime_type']);
        self::assertFileExists($this->config->mediaPath . '/' . $stored['full_path']);
        self::assertFileExists($this->config->mediaPath . '/' . $stored['thumbnail_path']);
        $full = new Imagick($this->config->mediaPath . '/' . $stored['full_path']);
        $thumbnail = new Imagick($this->config->mediaPath . '/' . $stored['thumbnail_path']);
        self::assertSame('WEBP', $full->getImageFormat());
        self::assertSame([], $full->getImageProperties('exif:*'));
        self::assertSame(480, $thumbnail->getImageWidth());
        self::assertSame(320, $thumbnail->getImageHeight());
        $full->clear();
        $thumbnail->clear();

        $imageResponse = $this->dashboardRequest($firstPhoto['full_url']);
        self::assertSame(200, $imageResponse->getStatusCode());
        self::assertSame('image/webp', $imageResponse->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $imageResponse->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(401, $this->dashboardRequest($firstPhoto['full_url'], false)->getStatusCode());

        $overview = $this->json($this->dashboardRequest('/api/v1/dashboard/overview?hours=24'));
        self::assertSame($firstPhoto['photo_id'], $overview['measurement_points'][0]['photo']['photo_id']);
        self::assertSame($firstPhoto['photo_id'], $overview['devices'][0]['photo']['photo_id']);

        $second = $this->json($this->uploadPhoto($pointId, $this->jpeg('second', 2400, 1600)))['photo'];
        self::assertSame(2, $second['revision']);
        self::assertSame(2048, $second['width']);
        self::assertSame(1365, $second['height']);
        $history = $this->json($this->dashboardRequest('/api/v1/dashboard/measurement-points/' . $pointId . '/photos'));
        self::assertCount(2, $history['photos']);
        self::assertSame([2, 1], array_column($history['photos'], 'revision'));
        self::assertSame([true, false], array_column($history['photos'], 'is_current'));
    }

    public function testCurrentPhotoDeletionRequiresPasswordAndPromotesPreviousRevision(): void
    {
        $pointId = $this->pointId();
        $first = $this->json($this->uploadPhoto($pointId, $this->jpeg('first')))['photo'];
        $second = $this->json($this->uploadPhoto($pointId, $this->jpeg('second')))['photo'];

        $wrong = $this->dashboardRequest('/api/v1/dashboard/photos/' . $second['photo_id'], true, 'DELETE', ['current_password' => 'incorrect-password']);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertSame('CURRENT_PASSWORD_INVALID', $this->json($wrong)['error']['code']);

        $deleted = $this->dashboardRequest('/api/v1/dashboard/photos/' . $second['photo_id'], true, 'DELETE', [
            'current_password' => $this->config->dashboardPassword,
        ]);
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        self::assertSame($first['photo_id'], $this->json($deleted)['current_photo_id']);
        self::assertSame(404, $this->dashboardRequest($second['full_url'])->getStatusCode());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM measurement_point_photos WHERE deleted_at IS NOT NULL')->fetchColumn());
        self::assertSame($first['photo_id'], $this->pdo->query('SELECT public_id FROM measurement_point_photos WHERE is_current = 1')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'measurement_point.photo_deleted'")->fetchColumn());
    }

    public function testInvalidMimeTypeAndMissingCsrfAreRejected(): void
    {
        $pointId = $this->pointId();
        $file = tempnam(sys_get_temp_dir(), 'haccp-invalid-');
        file_put_contents($file, 'not an image');
        $invalid = $this->uploadPhoto($pointId, $file, true, 'text/plain');
        self::assertSame(415, $invalid->getStatusCode());
        self::assertSame('UNSUPPORTED_IMAGE_FORMAT', $this->json($invalid)['error']['code']);

        $csrf = $this->uploadPhoto($pointId, $this->jpeg('csrf'), false);
        self::assertSame(403, $csrf->getStatusCode());
        self::assertSame('CSRF_FAILED', $this->json($csrf)['error']['code']);
    }

    private function pointId(): int
    {
        return (int) $this->pdo->query("SELECT id FROM measurement_points WHERE code = 'fridge-1'")->fetchColumn();
    }

    private function jpeg(string $label, int $width = 800, int $height = 600): string
    {
        $file = tempnam(sys_get_temp_dir(), 'haccp-photo-');
        $image = new Imagick();
        $image->newImage($width, $height, '#356f67');
        $image->setImageFormat('jpeg');
        $image->setImageProperty('comment', 'private-' . $label);
        $image->setImageProperty('exif:GPSLatitude', '52/1,31/1,0/1');
        $image->writeImage($file);
        $image->clear();

        return $file;
    }

    private function uploadPhoto(int $pointId, string $file, bool $csrf = true, string $mime = 'image/jpeg'): \Psr\Http\Message\ResponseInterface
    {
        $size = filesize($file);
        $stream = (new StreamFactory())->createStreamFromFile($file);
        $upload = new UploadedFile($stream, basename($file), $mime, $size === false ? null : $size);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/dashboard/measurement-points/' . $pointId . '/photos')
            ->withHeader('Cookie', 'haccp_session=' . $this->sessionCookie)
            ->withCookieParams(['haccp_session' => $this->sessionCookie])
            ->withUploadedFiles(['photo' => $upload]);
        if ($csrf) {
            $request = $request->withHeader('X-CSRF-Token', $this->csrfToken);
        }

        return $this->app->handle($request);
    }
}
