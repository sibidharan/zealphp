<?php

namespace ZealPHP;
use function ZealPHP\elog;
// class streamWrapper {
//     /* Properties */
//     public resource $context;
//     /* Methods */
//     public __construct()
//     public dir_closedir(): bool
//     public dir_opendir(string $path, int $options): bool
//     public dir_readdir(): string
//     public dir_rewinddir(): bool
//     public mkdir(string $path, int $mode, int $options): bool
//     public rename(string $path_from, string $path_to): bool
//     public rmdir(string $path, int $options): bool
//     public stream_cast(int $cast_as): resource
//     public stream_close(): void
//     public stream_eof(): bool
//     public stream_flush(): bool
//     public stream_lock(int $operation): bool
//     public stream_metadata(string $path, int $option, mixed $value): bool
//     public stream_open(
//         string $path,
//         string $mode,
//         int $options,
//         ?string &$opened_path
//     ): bool
//     public stream_read(int $count): string|false
//     public stream_seek(int $offset, int $whence = SEEK_SET): bool
//     public stream_set_option(int $option, int $arg1, int $arg2): bool
//     public stream_stat(): array|false
//     public stream_tell(): int
//     public stream_truncate(int $new_size): bool
//     public stream_write(string $data): int
//     public unlink(string $path): bool
//     public url_stat(string $path, int $flags): array|false
//     public __destruct()
// }

// Custom Stream Wrapper for php://input with passthrough
/**
 * Class IOStreamWrapper
 *
 * Custom stream wrapper for php:// streams that buffers php://input in memory and delegates other php:// streams to the default PHP wrapper.
 */
class IOStreamWrapper {
    public $context;
    private $position = 0;
    private $input = '';
    
    /**
     * Open a php:// stream. Buffers php://input in memory if requested; delegates other streams to the default PHP wrapper.
     *
     * @param mixed $path
     * @param mixed $mode
     * @param mixed $options
     * @param mixed $opened_path
     * @return mixed
     */
    public function stream_open($path, $mode, $options, &$opened_path) {
        elog("stream_open: $path, $mode, $options", "streamio");
        // Handle php://input specifically: load content into an in-memory stream
        if ($path === 'php://input') {
            $g = \ZealPHP\G::instance();
            $content = $g->zealphp_request->parent->getContent();
            $stream = fopen('php://memory', 'r+');
            if ($stream === false) {
                elog("Failed to open php://memory for php://input");
                return false;
            }
            fwrite($stream, $content);
            rewind($stream);
            $this->context = $stream;
            return true;
        }

        // Temporarily restore the default wrapper for other php:// streams
        stream_wrapper_restore('php');
        $handle = fopen($path, $mode); // Delegate to original stream
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', IOStreamWrapper::class);

        if ($handle !== false) {
            $this->context = $handle;
            return true;
        }
        elog("Failed to open stream: $path");
        return false; // Fail if the original stream couldn't open
    }
    

    /**
     * Read data from the stream, returning buffered php://input data or delegating to the underlying stream resource.
     *
     * @param mixed $count
     * @return mixed
     */
    public function stream_read($count) {
        if ($this->context) {
            // Passthrough read for other streams
            return fread($this->context, $count);
        } else {
            // Send to php://input
            $data = substr($this->input, $this->position, $count);
            $this->position += strlen($data);
            return $data;
        }
    }

    /**
     * Write data to the stream, delegating to the underlying stream resource or returning false for buffered input.
     *
     * @param mixed $data
     * @return mixed
     */
    public function stream_write($data) {
        if ($this->context) {
            // Passthrough write for other streams
            return fwrite($this->context, $data);
        }

        // Writing is not applicable for php://input
        return false;
    }

    /**
     * Determine if end-of-file has been reached for the stream or buffered input.
     *
     * @return mixed
     */
    public function stream_eof() {
        if ($this->context) {
            // Passthrough EOF for other streams
            return feof($this->context);
        }

        // EOF for php://input
        return $this->position >= strlen($this->input);
    }

    /**
     * Retrieve metadata for the stream resource or return an empty array for buffered input.
     *
     * @return mixed
     */
    public function stream_stat() {
        if ($this->context) {
            // Passthrough stat for other streams
            return fstat($this->context);
        }

        // Provide empty stats for php://input
        return [];
    }

    /**
     * Close the stream resource or no-op for buffered input.
     *
     * @return mixed
     */
    public function stream_close() {
        if ($this->context) {
            // Passthrough close for other streams
            fclose($this->context);
        }
    }

    /**
     * Rewind the stream resource or reset the read position for buffered input.
     *
     * @return mixed
     */
    public function stream_rewind() {
        if ($this->context) {
            // Passthrough rewind for other streams
            return rewind($this->context);
        } else {
            // Rewind for php://input
            $this->position = 0;
            return true;
        }
    }

    /**
     * Seek to a specified position in the stream or buffered input.
     *
     * @param mixed $offset
     * @param mixed $whence
     * @return mixed
     */
    public function stream_seek($offset, $whence = SEEK_SET) {
        if ($this->context) {
            // Passthrough seek for other streams (resource)
            if (is_resource($this->context)) {
                return fseek($this->context, $offset, $whence) === 0;
            }
            // Passthrough seek for PSR Stream instance
            if (is_object($this->context) && method_exists($this->context, 'seek')) {
                $this->context->seek($offset, $whence);
                return true;
            }
            return false;
        }

        // Seek for php://input stream: adjust position manually
        $length = strlen($this->input);
        switch ($whence) {
            case SEEK_SET:
                if ($offset >= 0 && $offset <= $length) {
                    $this->position = $offset;
                    return true;
                }
                return false;
            case SEEK_CUR:
                $new = $this->position + $offset;
                if ($new >= 0 && $new <= $length) {
                    $this->position = $new;
                    return true;
                }
                return false;
            case SEEK_END:
                $new = $length + $offset;
                if ($new >= 0 && $new <= $length) {
                    $this->position = $new;
                    return true;
                }
                return false;
            default:
                return false;
        }
    }

    /**
     * Return the current read/write position of the stream or buffered input.
     *
     * @return mixed
     */
    public function stream_tell() {
        if ($this->context) {
            // Passthrough tell for other streams
            return ftell($this->context);
        }

        // Tell for php://input
        return $this->position;
    }

    /**
     * Truncate the stream to a given size or no-op for buffered input.
     *
     * @param mixed $new_size
     * @return mixed
     */
    public function stream_truncate($new_size) {
        if ($this->context) {
            // Passthrough truncate for other streams
            return ftruncate($this->context, $new_size);
        }

        // Truncate is not applicable for php://input
        return false;
    }

    /**
     * Flush the stream buffer or no-op for buffered input.
     *
     * @return mixed
     */
    public function stream_flush() {
        if ($this->context) {
            // Passthrough flush for other streams
            return fflush($this->context);
        }

        // Flush is not applicable for php://input
        return false;
    }

    /**
     * Acquire or release a lock on the stream or no-op for buffered input.
     *
     * @param mixed $operation
     * @return mixed
     */
    public function stream_lock($operation) {
        if ($this->context) {
            // Passthrough lock for other streams
            return flock($this->context, $operation);
        }

        // Lock is not applicable for php://input
        return false;
    }

    /**
     * Retrieve information about a URL path or no-op for buffered input streams.
     *
     * @param mixed $path
     * @param mixed $flags
     * @return mixed
     */
    public function url_stat($path, $flags) {
        if ($this->context) {
            // Passthrough url_stat for other streams
            return stat($path);
        }

        // URL stat is not applicable for php://input
        return false;
    }

    /**
     * Delete a file via the stream resource or no-op for buffered input.
     *
     * @param mixed $path
     * @return mixed
     */
    public function stream_unlink($path) {
        if ($this->context) {
            // Passthrough unlink for other streams
            return unlink($path);
        }

        // Unlink is not applicable for php://input
        return false;
    }

    # write magic method __get and __call for all other methods
    /**
     * Pass property access through to the underlying stream resource.
     *
     * @param mixed $name
     * @return mixed
     */
    public function __get($name) {
        if ($this->context) {
            return $this->context->$name;
        }
    }

    /**
     * Forward method calls to the underlying stream resource.
     *
     * @param mixed $name
     * @param mixed $args
     * @return mixed
     */
    public function __call($name, $args) {
        if ($this->context) {
            return $this->context->$name(...$args);
            // return call_user_func_array([, $name], $args);
        }
    }

}
