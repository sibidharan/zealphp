<?php
namespace ZealPHP\HTTP\Factory;

use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\StreamInterface;
use OpenSwoole\Core\Psr\UploadedFile;

class UploadedFileFactory implements UploadedFileFactoryInterface
{
    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = \UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): UploadedFileInterface {
        return new UploadedFile(
            $stream,
            $size ?? $stream->getSize() ?? 0,
            $error,
            $clientFilename,
            $clientMediaType,
        );
    }
}
