<?php
declare(strict_types=1);

namespace ZealPHP\Diagnostics;

use ZealPHP\RequestContext;
use Throwable;

/**
 * Renders `phpinfo()` as a self-contained HTML document, restoring Apache+mod_php
 * parity for the CLI SAPI (which otherwise emits a plain-text key => value dump).
 *
 * Pure renderer: no echo, no global writes. The uopz override target
 * `\ZealPHP\phpinfo()` echoes `render()` and returns `true`.
 *
 * Robustness: a diagnostics page must never fatal. Request-variable collection
 * is guarded so `render()` works in any context (request handler, CLI, unit test).
 */
final class PhpInfo
{
    /** Boot-captured native `phpinfo(INFO_MODULES)` text, primed once per worker. */
    private static string $moduleText = '';

    /** Store the native module-section text captured before the uopz override. */
    public static function primeModuleText(string $text): void
    {
        self::$moduleText = $text;
    }

    /**
     * @param int                                           $flags       `INFO_*` bitmask (matches native `phpinfo()`).
     * @param array<string, array<int|string, mixed>>|null  $requestVars Test seam: `['_GET'=>..., '_POST'=>..., '_COOKIE'=>..., '_SERVER'=>...]`. Null pulls from `G`.
     */
    public static function render(int $flags = INFO_ALL, ?array $requestVars = null): string
    {
        $body = self::renderGeneral();
        if (($flags & INFO_CONFIGURATION) !== 0) {
            $body .= self::renderConfiguration();
        }
        if (($flags & INFO_MODULES) !== 0) {
            $body .= self::renderModules();
        }
        if (($flags & INFO_VARIABLES) !== 0) {
            $body .= self::renderVariables($requestVars ?? self::collectRequestVars());
        }
        return self::document($body);
    }

    private static function document(string $body): string
    {
        return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\">"
            . '<title>PHP Info — ZealPHP</title>' . self::styles()
            . "</head><body><div class=\"zphp-info\">{$body}</div></body></html>";
    }

    private static function styles(): string
    {
        return '<style>'
            . '.zphp-info{font-family:system-ui,sans-serif;max-width:960px;margin:2rem auto;color:#1a1a1a}'
            . '.zphp-info h2{background:#1a1a1a;color:#ffb000;padding:.5rem .75rem;margin:1.5rem 0 0;border-radius:6px 6px 0 0}'
            . '.zphp-info table{width:100%;border-collapse:collapse;margin:0 0 1rem}'
            . '.zphp-info td,.zphp-info th{border:1px solid #ddd;padding:.4rem .6rem;text-align:left;word-break:break-word}'
            . '.zphp-info th{background:#f5f5f5;width:35%}'
            . '.zphp-info pre{background:#f5f5f5;padding:.6rem;overflow:auto;border:1px solid #ddd}'
            . '</style>';
    }

    private static function renderGeneral(): string
    {
        $rows = [
            'PHP Version'  => phpversion(),
            'System'       => php_uname(),
            'Server API'   => 'ZealPHP (OpenSwoole ' . self::openswooleVersion() . ')',
            'Zend Version' => zend_version(),
        ];
        return self::section('PHP Core', $rows);
    }

    private static function renderConfiguration(): string
    {
        $all  = ini_get_all(null, true); // grouped: directive => [local_value, global_value, access]
        $html = '<h2>Configuration (core directives)</h2>'
            . '<table><tr><th>Directive</th><th>Local / Master</th></tr>';
        if ($all === false) {
            return $html . '</table>';
        }
        foreach ($all as $directive => $info) {
            if (!is_array($info)) {
                continue;
            }
            $local  = self::iniField($info, 'local_value');
            $master = self::iniField($info, 'global_value');
            $html  .= '<tr><th>' . self::e((string) $directive) . '</th><td>'
                . self::e($local . ' / ' . $master) . '</td></tr>';
        }
        return $html . '</table>';
    }

    private static function renderModules(): string
    {
        $exts = get_loaded_extensions(); // list<string>
        sort($exts);
        $html = '<h2>Loaded Extensions</h2><table>';
        foreach ($exts as $ext) {
            $ver   = phpversion($ext);
            $html .= '<tr><th>' . self::e($ext) . '</th><td>'
                . self::e(is_string($ver) && $ver !== '' ? $ver : 'enabled') . '</td></tr>';
        }
        $html .= '</table>';
        // Free-form per-module detail captured natively at boot: render verbatim
        // so we never miss extension-specific rows ini_get_all cannot reach.
        if (self::$moduleText !== '') {
            $html .= '<h2>Module Details (native)</h2><pre>'
                . self::e(self::$moduleText) . '</pre>';
        }
        return $html;
    }

    /** @param array<string, array<int|string, mixed>> $vars */
    private static function renderVariables(array $vars): string
    {
        $out = '';
        foreach (['_SERVER', '_GET', '_POST', '_COOKIE'] as $key) {
            $bag = $vars[$key] ?? [];
            if ($bag === []) {
                continue;
            }
            $rows = [];
            foreach ($bag as $k => $v) {
                $rows['$' . $key . "['" . (string) $k . "']"] = self::stringify($v);
            }
            $out .= self::section('PHP Variables — $' . $key, $rows);
        }
        return $out;
    }

    /** @param array<string, string> $rows */
    private static function section(string $title, array $rows): string
    {
        $html = '<h2>' . self::e($title) . '</h2><table>';
        foreach ($rows as $k => $v) {
            $html .= '<tr><th>' . self::e($k) . '</th><td>' . self::e($v) . '</td></tr>';
        }
        return $html . '</table>';
    }

    /** @return array<string, array<int|string, mixed>> */
    private static function collectRequestVars(): array
    {
        try {
            $g = RequestContext::instance();
            return [
                '_SERVER' => self::toArray($g->server),
                '_GET'    => self::toArray($g->get),
                '_POST'   => self::toArray($g->post),
                '_COOKIE' => self::toArray($g->cookie),
            ];
        } catch (Throwable) {
            // No request/coroutine context (e.g. CLI, unit test). A diagnostics
            // page must never fatal — degrade to empty variable bags.
            return [];
        }
    }

    /** @return array<int|string, mixed> */
    private static function toArray(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }

    /** @param array<array-key, mixed> $info */
    private static function iniField(array $info, string $key): string
    {
        $v = $info[$key] ?? '';
        return is_scalar($v) ? (string) $v : '';
    }

    private static function stringify(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        return is_array($v) ? '(array)' : gettype($v);
    }

    private static function openswooleVersion(): string
    {
        $v = phpversion('openswoole');
        return is_string($v) && $v !== '' ? $v : 'unknown';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
