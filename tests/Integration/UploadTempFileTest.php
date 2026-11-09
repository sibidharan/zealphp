<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * OpenSwoole deletes a request's upload temp files (`swoole.upfile.*`) when
 * the request object dies. Two framework bugs kept them on disk: a PHP
 * reference on the request's `tmpfiles` property hid the list from OpenSwoole
 * (files never deleted), and a RequestContext <-> Response cycle kept every
 * request alive until the cycle collector ran. Either way, uploads filled /tmp.
 */
class UploadTempFileTest extends TestCase
{
    /** @return list<string> */
    private static function upfiles(): array
    {
        $dir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
        return glob(rtrim($dir, '/') . '/swoole.upfile.*') ?: [];
    }

    public function testMultipartRequestsLeaveNoTempFiles(): void
    {
        if (!in_array(TEST_SERVER_HOST, ['127.0.0.1', 'localhost'], true)) {
            $this->markTestSkipped('needs the server on this host to see its temp dir');
        }
        $payload = tempnam(sys_get_temp_dir(), 'zp-up');
        $this->assertIsString($payload);
        file_put_contents($payload, random_bytes(4096));
        $before = self::upfiles();

        try {
            // A matched route, a public file and a 404 all end the request.
            foreach (['/', '/test', '/no-such-page'] as $path) {
                for ($i = 0; $i < 3; $i++) {
                    $ch = curl_init(TEST_SERVER_URL . $path);
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => ['f' => new \CURLFile($payload)],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 10,
                    ]);
                    $this->assertNotFalse(curl_exec($ch));
                    curl_close($ch);
                }
            }
        } finally {
            unlink($payload);
        }

        usleep(300000);
        $left = array_diff(self::upfiles(), $before);
        $this->assertSame([], array_values($left), 'upload temp files left behind');
    }
}
