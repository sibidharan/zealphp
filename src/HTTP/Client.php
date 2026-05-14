<?php
namespace ZealPHP\HTTP;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ZealPHP\HTTP\Client\NetworkException;
use ZealPHP\HTTP\Client\RequestException;
use OpenSwoole\Core\Psr\Response;

class Client implements ClientInterface
{
    private int $timeout;
    private bool $verifySsl;
    private int $maxRedirects;

    public function __construct(array $options = [])
    {
        $this->timeout = $options['timeout'] ?? 30;
        $this->verifySsl = $options['verify_ssl'] ?? true;
        $this->maxRedirects = $options['max_redirects'] ?? 5;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = (string) $request->getUri();
        if ($uri === '') {
            throw new RequestException($request, 'Request URI must not be empty');
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $uri,
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => $this->maxRedirects > 0,
            CURLOPT_MAXREDIRS => $this->maxRedirects,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = "{$name}: {$value}";
            }
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = (string) $request->getBody();
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $rawResponse = curl_exec($ch);

        if ($rawResponse === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new NetworkException($request, "cURL error {$errno}: {$error}", $errno);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($rawResponse, 0, $headerSize);
        $responseBody = substr($rawResponse, $headerSize);

        // When following redirects, multiple header blocks may be present.
        // Parse only the last one (the final response).
        $headerBlocks = preg_split('/\r\n\r\n/', trim($rawHeaders));
        $lastBlock = end($headerBlocks);

        $responseHeaders = [];
        $reasonPhrase = '';
        foreach (explode("\r\n", $lastBlock) as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                $parts = explode(' ', $line, 3);
                $reasonPhrase = $parts[2] ?? '';
                continue;
            }
            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $name = trim(substr($line, 0, $colonPos));
                $value = trim(substr($line, $colonPos + 1));
                $responseHeaders[$name][] = $value;
            }
        }

        return new Response($responseBody, $statusCode, $reasonPhrase, $responseHeaders);
    }
}
