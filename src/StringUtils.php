<?php
namespace ZealPHP;

class StringUtils
{
    public static function str_starts_with(string $haystack, string $needle): bool
    {
        $length = strlen($needle);
        return (substr($haystack, 0, $length) === $needle);
    }

    public static function str_ends_with(string $haystack, string $needle): bool
    {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }
        return (substr($haystack, -$length) === $needle);
    }

    /**
     * Return the substring between two string delimiters.
     *
     * @param string $string
     * @param string $start  Opening delimiter
     * @param string $end    Closing delimiter
     * @return string The substring strictly between $start and $end, or '' if
     *                either delimiter is absent.
     */
    public static function get_string_between($string, $start, $end)
    {
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) {
            return '';
        }

        $ini += strlen($start);
        // #448 — guard the missing-end case. strpos() returns false when $end is
        // absent; the old unguarded `false - $ini` is a negative length, so
        // substr() returned a corrupt offset-dependent slice instead of ''.
        // No closing delimiter → no "between".
        $end_pos = strpos($string, $end, $ini);
        if ($end_pos === false) {
            return '';
        }
        return substr($string, $ini, $end_pos - $ini);
    }


   public static function str_contains(string $haystack, string $needle): bool
   {
       return strpos($haystack, $needle) !== false;
   }
}