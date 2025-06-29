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
class IOStreamWrapper {
    public $context;
    private $position = 0;
    private $input = '';

    // public function stream_open($path, $mode, $options, &$opened_path) {
    //     if ($path === 'php://input') {
    //         // Read the entire php://input into memory
    //         $this->input = file_get_contents('php://input');
    //         $this->position = 0;
    //         return true;
    //     } else {
    //         // For other streams, open the context normally
    //         $this->context = fopen($path, $mode);
    //         return $this->context !== false;
    //     }
    // }

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

    public function stream_write($data) {
        if ($this->context) {
            // Passthrough write for other streams
            return fwrite($this->context, $data);
        }

        // Writing is not applicable for php://input
        return false;
    }

    public function stream_eof() {
        if ($this->context) {
            // Passthrough EOF for other streams
            return feof($this->context);
        }

        // EOF for php://input
        return $this->position >= strlen($this->input);
    }

    public function stream_stat() {
        if ($this->context) {
            // Passthrough stat for other streams
            return fstat($this->context);
        }

        // Provide empty stats for php://input
        return [];
    }

    public function stream_close() {
        if ($this->context) {
            // Passthrough close for other streams
            fclose($this->context);
        }
    }

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

    public function stream_tell() {
        if ($this->context) {
            // Passthrough tell for other streams
            return ftell($this->context);
        }

        // Tell for php://input
        return $this->position;
    }

    public function stream_truncate($new_size) {
        if ($this->context) {
            // Passthrough truncate for other streams
            return ftruncate($this->context, $new_size);
        }

        // Truncate is not applicable for php://input
        return false;
    }

    public function stream_flush() {
        if ($this->context) {
            // Passthrough flush for other streams
            return fflush($this->context);
        }

        // Flush is not applicable for php://input
        return false;
    }

    public function stream_lock($operation) {
        if ($this->context) {
            // Passthrough lock for other streams
            return flock($this->context, $operation);
        }

        // Lock is not applicable for php://input
        return false;
    }

    public function url_stat($path, $flags) {
        if ($this->context) {
            // Passthrough url_stat for other streams
            return stat($path);
        }

        // URL stat is not applicable for php://input
        return false;
    }

    public function stream_unlink($path) {
        if ($this->context) {
            // Passthrough unlink for other streams
            return unlink($path);
        }

        // Unlink is not applicable for php://input
        return false;
    }

    # write magic method __get and __call for all other methods
    public function __get($name) {
        if ($this->context) {
            return $this->context->$name;
        }
    }

    public function __call($name, $args) {
        if ($this->context) {
            return $this->context->$name(...$args);
            // return call_user_func_array([, $name], $args);
        }
    }

}
