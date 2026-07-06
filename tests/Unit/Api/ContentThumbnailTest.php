<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ContentController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Upload de miniatura: valida que só imagens passam, que a URL do anexo volta no
 * sucesso e que erro do media_handle_upload é propagado. media_handle_upload e
 * wp_get_attachment_url são stubados no bootstrap (controlados por globais).
 */
final class ContentThumbnailTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new \wpdb();
        unset($GLOBALS['gk_media_result'], $GLOBALS['gk_attachment_url']);
    }

    private function reqWithFile(array $file): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/content/thumbnail');
        $req->set_file_params($file);
        return $req;
    }

    public function testRejectsWhenNoFile(): void
    {
        $res = (new ContentController())->uploadThumbnail(new WP_REST_Request('POST', '/content/thumbnail'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('no_file', $res->get_error_code());
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testRejectsNonImageMime(): void
    {
        $res = (new ContentController())->uploadThumbnail($this->reqWithFile(['file' => ['type' => 'application/pdf']]));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('invalid_type', $res->get_error_code());
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testReturnsUrlOnSuccess(): void
    {
        $GLOBALS['gk_media_result'] = 99;
        $GLOBALS['gk_attachment_url'] = 'https://guardiaokids.site/wp-content/uploads/2026/07/foto.png';

        $res = (new ContentController())->uploadThumbnail($this->reqWithFile(['file' => ['type' => 'image/png']]));

        self::assertSame(201, $res->get_status());
        self::assertSame(99, $res->get_data()['id']);
        self::assertSame('https://guardiaokids.site/wp-content/uploads/2026/07/foto.png', $res->get_data()['url']);
    }

    public function testPropagatesMediaHandleError(): void
    {
        $GLOBALS['gk_media_result'] = new WP_Error('upload_error', 'disco cheio', ['status' => 500]);

        $res = (new ContentController())->uploadThumbnail($this->reqWithFile(['file' => ['type' => 'image/jpeg']]));

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('upload_error', $res->get_error_code());
    }
}
