<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\HTTP;

/**
 * Outbound HTTPS verifies the server certificate. OpenSwoole's coroutine
 * client doesn't unless told to, so HTTP::get() accepted a self-signed (or
 * expired, or another host's) certificate: a man-in-the-middle could read
 * and change the traffic.
 */
class HTTPTlsVerifyTest extends TestCase
{
    public function testTlsOptionsVerifyThePeerAndHost(): void
    {
        $this->assertSame(
            ['ssl_verify_peer' => true, 'ssl_allow_self_signed' => false, 'ssl_host_name' => 'api.example.com'],
            HTTP::tlsOptions('api.example.com')
        );
    }

    public function testASelfSignedServerIsRefused(): void
    {
        if (!function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('needs ext-openssl');
        }
        $dir = sys_get_temp_dir() . '/zp-tls-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        [$cert, $key] = self::selfSigned($dir);

        $result = [];
        // The refused handshakes are logged by OpenSwoole as notices/warnings;
        // keep them off the test runner's stderr.
        Coroutine::set(['log_level' => 5]);
        try {
            Coroutine::run(function () use ($cert, $key, &$result): void {
                $server = new Coroutine\Socket(AF_INET, SOCK_STREAM, 0);
                try {
                    $server->setProtocol(['open_ssl' => true, 'ssl_cert_file' => $cert, 'ssl_key_file' => $key]);
                } catch (\Throwable $e) {
                    $result['no_ssl'] = $e->getMessage();   // OpenSwoole built without OpenSSL
                    return;
                }
                $server->bind('127.0.0.1', 0);
                $server->listen();
                $name = $server->getsockname();
                $port = is_array($name) ? (int) $name['port'] : 0;
                Coroutine::create(function () use ($server): void {
                    for ($i = 0; $i < 2; $i++) {
                        $conn = $server->accept(5);
                        if ($conn === false) {
                            break;
                        }
                        if ($conn->sslHandshake()) {
                            $conn->recv(8192, 5);
                            $conn->send("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
                        }
                        $conn->close();
                    }
                    $server->close();
                });

                // The server works: a client that doesn't verify gets the page.
                try {
                    $raw = new Coroutine\Http\Client('127.0.0.1', $port, true);
                } catch (\Throwable $e) {
                    // OpenSwoole built without OpenSSL: nothing to verify.
                    $result['no_ssl'] = $e->getMessage();
                    $server->close();
                    return;
                }
                $raw->set(['timeout' => 5]);
                $raw->get('/');
                $result['unverified'] = $raw->statusCode;
                $raw->close();

                $r = HTTP::get('https://127.0.0.1:' . $port . '/', [], 5);
                $result['zeal_failed'] = $r->failed();
                $result['zeal_status'] = $r->status;
            });
        } finally {
            Coroutine::set(['log_level' => 2]);
            @unlink($cert);
            @unlink($key);
            @rmdir($dir);
        }

        if (isset($result['no_ssl'])) {
            $this->markTestSkipped('OpenSwoole has no TLS support: ' . $result['no_ssl']);
        }
        $this->assertSame(200, $result['unverified'] ?? null);
        $this->assertTrue($result['zeal_failed'] ?? false, 'HTTP::get() must refuse the self-signed certificate');
        $this->assertSame(0, $result['zeal_status'] ?? null);
    }

    /** @return array{string, string} cert and key paths */
    private static function selfSigned(string $dir): array
    {
        $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($pkey);
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $pkey);
        self::assertNotFalse($csr);
        $x509 = openssl_csr_sign($csr, null, $pkey, 1);
        self::assertNotFalse($x509);
        $cert = $dir . '/cert.pem';
        $key = $dir . '/key.pem';
        openssl_x509_export_to_file($x509, $cert);
        openssl_pkey_export_to_file($pkey, $key);
        return [$cert, $key];
    }
}
