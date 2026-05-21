<?php
declare(strict_types=1);

namespace ZealPHP\HTTP;

/**
 * Multi-suffix MIME metadata resolver — Apache mod_mime `find_ct` parity.
 *
 * Apache walks EVERY dot-separated suffix of a filename left-to-right
 * (`mod_mime.c` `find_ct`, ~874–1007), accumulating Content-Type,
 * Content-Encoding, Content-Language and charset from each suffix. PHP's
 * `pathinfo(…, PATHINFO_EXTENSION)` only ever returns the rightmost suffix,
 * which is wrong for files such as `document.html.gz` (HTML body carried with
 * a gzip Content-Encoding) or `page.fr.html` (French HTML).
 *
 * This resolver reproduces Apache's algorithm exactly:
 *
 *  - **Basename rule** (`find_ct` lines 874–887): leading dots are part of the
 *    basename, and the segment up to the first *real* dot is the basename and
 *    carries no metadata. So `.png` is a hidden file named `png` with zero
 *    extensions — NO type is assigned (the M12 dotfile fix).
 *  - **Suffix walk** (`find_ct` lines 891–1007): each remaining suffix is
 *    lowercased and looked up independently. Empty suffixes ("bad..html") are
 *    skipped (line 898).
 *  - **Content-Type**: last matching suffix wins — Apache calls
 *    `ap_set_content_type` per match, overwriting (line 921/930).
 *  - **Content-Language**: every matching suffix is accumulated in order
 *    (lines 938–946, `apr_array_push`).
 *  - **Content-Encoding**: every matching suffix is accumulated in order,
 *    comma-joined; duplicates and double-encoding are intentionally preserved
 *    (lines 947–962, the `-- nd` comment).
 *
 * The resolver is pure: it takes three case-insensitive extension→value maps
 * (type, encoding, language) and a filename, and returns the resolved
 * metadata. It performs no I/O and never inspects file contents.
 */
class MimeResolver
{
    /** @var array<string, string> ext => mime-type (keys lowercased, dot-stripped) */
    private array $typeMap;

    /** @var array<string, string> ext => content-encoding (keys lowercased, dot-stripped) */
    private array $encodingMap;

    /** @var array<string, string> ext => content-language (keys lowercased, dot-stripped) */
    private array $languageMap;

    /**
     * @param array<string, string|int> $typeMap     ext => mime-type
     * @param array<string, string|int> $encodingMap ext => content-encoding (e.g. gz => gzip)
     * @param array<string, string|int> $languageMap ext => content-language (e.g. fr => fr)
     */
    public function __construct(array $typeMap = [], array $encodingMap = [], array $languageMap = [])
    {
        $this->typeMap = self::normaliseMap($typeMap);
        $this->encodingMap = self::normaliseMap($encodingMap);
        $this->languageMap = self::normaliseMap($languageMap);
    }

    /**
     * Normalise a caller-supplied extension map: lowercase + dot-strip keys,
     * stringify values (Apache lowercases stored values; we preserve the
     * caller's value casing, matching MimeTypeMiddleware's prior behaviour).
     *
     * @param  array<string, string|int> $map
     * @return array<string, string>
     */
    private static function normaliseMap(array $map): array
    {
        $out = [];
        foreach ($map as $ext => $value) {
            $out[strtolower(ltrim((string)$ext, '.'))] = (string)$value;
        }
        return $out;
    }

    /**
     * Walk every suffix of $filename and accumulate metadata.
     *
     * @param  string $filename basename or full path; only the basename's
     *                          suffix chain is considered.
     * @return array{type: ?string, encoding: ?string, languages: list<string>}
     *         `type` is null when no suffix mapped a Content-Type; `encoding`
     *         is the comma-joined encoding chain (null when none); `languages`
     *         is the ordered list of matched languages (empty when none).
     */
    public function resolve(string $filename): array
    {
        $type = null;
        $encodings = [];
        $languages = [];

        foreach ($this->suffixes($filename) as $ext) {
            if (isset($this->typeMap[$ext])) {
                $type = $this->typeMap[$ext]; // last match wins (Apache overwrites)
            }
            if (isset($this->encodingMap[$ext])) {
                $encodings[] = $this->encodingMap[$ext]; // accumulate, order preserved
            }
            if (isset($this->languageMap[$ext])) {
                $languages[] = $this->languageMap[$ext]; // accumulate, order preserved
            }
        }

        return [
            'type' => $type,
            'encoding' => $encodings === [] ? null : implode(', ', $encodings),
            'languages' => $languages,
        ];
    }

    /**
     * Decompose a filename into its lowercased suffix list, applying Apache's
     * basename rule (leading dots + segment before the first real dot are the
     * basename and excluded). Empty suffixes are dropped.
     *
     * `document.html.gz` => ['html', 'gz']
     * `.png`             => []   (hidden file, no extension)
     * `archive.TAR.GZ`   => ['tar', 'gz']
     * `noext`            => []
     *
     * @return list<string>
     */
    public function suffixes(string $filename): array
    {
        // Reduce a path to its final component (Apache works on the basename).
        $slash = strrpos($filename, '/');
        if ($slash !== false) {
            $filename = substr($filename, $slash + 1);
        }

        // Apache: skip leading dots, then the basename runs up to the first
        // real dot. A filename with no real dot after the leading dots has no
        // extensions at all.
        $scan = $filename;
        $len = strlen($scan);
        $i = 0;
        while ($i < $len && $scan[$i] === '.') {
            $i++;
        }
        $firstDot = strpos($scan, '.', $i);
        if ($firstDot === false) {
            return [];
        }

        // Everything after the first real dot is the suffix chain.
        $rest = substr($filename, $firstDot + 1);

        $out = [];
        foreach (explode('.', $rest) as $ext) {
            if ($ext === '') {
                continue; // ignore empty extensions ("bad..html")
            }
            $out[] = strtolower($ext);
        }
        return $out;
    }
}
