<?php

declare(strict_types=1);

namespace ZealPHP\CGI;

/**
 * php:// stream wrapper for the CGI subprocesses (proc `cgi_worker.php` and the
 * pooled `pool_worker.php`).
 *
 * It serves `php://input` from the CURRENT request's raw body, stashed in
 * `$GLOBALS['__zeal_cgi_raw_input']` by the worker before the target file runs.
 * This bridges an OpenSwoole-delivered request body (e.g. the JSON payload the
 * WordPress block editor PUT/POSTs to the REST API) to legacy code that reads
 * `file_get_contents('php://input')` — which native CLI `php://input` does NOT
 * provide. Every other `php://` stream (memory/temp/filter/fd/…) passes through
 * to the default wrapper unchanged.
 *
 * Counterpart to \ZealPHP\IOStreamWrapper (in-process modes, body from
 * RequestContext). String-backed for input (no php://memory round-trip).
 *
 * NOTE: `$context` is RESERVED — PHP injects the stream context resource into it,
 * so it must never be fclose()'d. The passthrough handle lives in `$fh`.
 */
class CgiInputStream
{
    /** @var resource|null PHP-injected stream context (reserved; never fclose). */
    public $context;
    /** @var resource|null Passthrough handle for non-input php:// streams. */
    private $fh = null;
    private string $input = '';
    private int $pos = 0;
    private bool $isInput = false;

    /**
     * @param string      $path
     * @param string      $mode
     * @param int         $options
     * @param string|null $opened_path
     */
    public function stream_open($path, $mode, $options, &$opened_path): bool
    {
        if ($path === 'php://input') {
            $b = $GLOBALS['__zeal_cgi_raw_input'] ?? '';
            $this->input   = is_string($b) ? $b : '';
            $this->pos     = 0;
            $this->isInput = true;
            return true;
        }
        // Delegate every other php:// stream to the original wrapper.
        stream_wrapper_restore('php');
        $h = @fopen($path, $mode);
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);
        if ($h === false) {
            return false;
        }
        $this->fh = $h;
        return true;
    }

    /**
     * @param int $count
     * @return string|false
     */
    public function stream_read($count)
    {
        if ($this->isInput) {
            if ($count < 1) {
                return '';
            }
            $d = substr($this->input, $this->pos, (int) $count);
            $this->pos += strlen($d);
            return $d;
        }
        return is_resource($this->fh) ? fread($this->fh, max(1, (int) $count)) : false;
    }

    /**
     * @param string $data
     * @return int|false
     */
    public function stream_write($data)
    {
        return is_resource($this->fh) ? fwrite($this->fh, (string) $data) : false;
    }

    public function stream_eof(): bool
    {
        if ($this->isInput) {
            return $this->pos >= strlen($this->input);
        }
        return is_resource($this->fh) ? feof($this->fh) : true;
    }

    /**
     * @param int $offset
     * @param int $whence
     */
    public function stream_seek($offset, $whence = SEEK_SET): bool
    {
        if ($this->isInput) {
            $len = strlen($this->input);
            $new = match ((int) $whence) {
                SEEK_SET => (int) $offset,
                SEEK_CUR => $this->pos + (int) $offset,
                SEEK_END => $len + (int) $offset,
                default  => -1,
            };
            if ($new < 0 || $new > $len) {
                return false;
            }
            $this->pos = $new;
            return true;
        }
        return is_resource($this->fh) ? fseek($this->fh, (int) $offset, (int) $whence) === 0 : false;
    }

    /** @return int */
    public function stream_tell()
    {
        if ($this->isInput) {
            return $this->pos;
        }
        return is_resource($this->fh) ? (int) ftell($this->fh) : 0;
    }

    /** @return array<int|string,mixed>|false */
    public function stream_stat()
    {
        if ($this->isInput) {
            return ['size' => strlen($this->input)];
        }
        return is_resource($this->fh) ? fstat($this->fh) : [];
    }

    /**
     * @param int $option
     * @param int $arg1
     * @param int $arg2
     */
    public function stream_set_option($option, $arg1, $arg2): bool
    {
        return true; // no-op success — avoids "not implemented" warnings
    }

    public function stream_close(): void
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
        $this->fh = null;
    }
}
