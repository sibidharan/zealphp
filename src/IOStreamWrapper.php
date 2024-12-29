<?php

namespace ZealPHP;

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
    private $position = 0;
    private $input = '';
    private $defaultStream = null;

    public function stream_open($path, $mode, $options, &$opened_path) {
        // Handle php://input specifically
        if ($path === 'php://input') {
            $g = \ZealPHP\G::instance();
            $this->input = $g->zealphp_request->parent->getContent();
            $this->position = 0;
            return true;
        }

        // Temporarily restore the default wrapper for other php:// streams
        elog(var_export(stream_get_wrappers(), true));
        stream_wrapper_restore('php');
        elog("Opening stream: $path");
        $handle = fopen($path, $mode); // Delegate to original stream
        
        // Re-register this custom wrapper after the stream is opened
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);

        if ($handle !== false) {
            $this->defaultStream = $handle;
            return true;
        }
        elog("Failed to open stream: $path");
        return false; // Fail if the original stream couldn't open
    }

    public function stream_read($count) {
        if ($this->defaultStream) {
            // Passthrough read for other streams
            return fread($this->defaultStream, $count);
        }

        // Read from php://input
        $data = substr($this->input, $this->position, $count);
        $this->position += strlen($data);
        return $data;
    }

    public function stream_write($data) {
        if ($this->defaultStream) {
            // Passthrough write for other streams
            return fwrite($this->defaultStream, $data);
        }

        // Writing is not applicable for php://input
        return false;
    }

    public function stream_eof() {
        if ($this->defaultStream) {
            // Passthrough EOF for other streams
            return feof($this->defaultStream);
        }

        // EOF for php://input
        return $this->position >= strlen($this->input);
    }

    public function stream_stat() {
        if ($this->defaultStream) {
            // Passthrough stat for other streams
            return fstat($this->defaultStream);
        }

        // Provide empty stats for php://input
        return [];
    }

    public function stream_close() {
        if ($this->defaultStream) {
            // Passthrough close for other streams
            fclose($this->defaultStream);
        }
    }

    public function stream_rewind() {
        if ($this->defaultStream) {
            // Passthrough rewind for other streams
            rewind($this->defaultStream);
        } else {
            // Rewind for php://input
            $this->position = 0;
        }
    }

    public function stream_seek($offset, $whence) {
        if ($this->defaultStream) {
            // Passthrough seek for other streams
            return fseek($this->defaultStream, $offset, $whence);
        }

        // Seek is not applicable for php://input
        return false;
    }

    public function stream_tell() {
        if ($this->defaultStream) {
            // Passthrough tell for other streams
            return ftell($this->defaultStream);
        }

        // Tell for php://input
        return $this->position;
    }

    public function stream_truncate($new_size) {
        if ($this->defaultStream) {
            // Passthrough truncate for other streams
            return ftruncate($this->defaultStream, $new_size);
        }

        // Truncate is not applicable for php://input
        return false;
    }

    public function stream_flush() {
        if ($this->defaultStream) {
            // Passthrough flush for other streams
            return fflush($this->defaultStream);
        }

        // Flush is not applicable for php://input
        return false;
    }

    public function stream_lock($operation) {
        if ($this->defaultStream) {
            // Passthrough lock for other streams
            return flock($this->defaultStream, $operation);
        }

        // Lock is not applicable for php://input
        return false;
    }

    public function stream_url_stat($path, $flags) {
        if ($this->defaultStream) {
            // Passthrough url_stat for other streams
            return stat($path);
        }

        // URL stat is not applicable for php://input
        return false;
    }

    public function stream_unlink($path) {
        if ($this->defaultStream) {
            // Passthrough unlink for other streams
            return unlink($path);
        }

        // Unlink is not applicable for php://input
        return false;
    }

    # write magic method __get and __call for all other methods
    public function __get($name) {
        if ($this->defaultStream) {
            return $this->defaultStream->$name;
        }
    }

    public function __call($name, $args) {
        if ($this->defaultStream) {
            return call_user_func_array([$this->defaultStream, $name], $args);
        }
    }

}
