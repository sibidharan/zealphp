<?php
namespace ZealPHP;

class StringUtils
{
    public static function str_starts_with($haystack, $needle)
    {
        $length = strlen($needle);
        return (substr($haystack, 0, $length) === $needle);
    }

    public static function str_ends_with($haystack, $needle)
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
     * @return string The sliced string, or '' if $start was not found.
     */
    public static function get_string_between($string, $start, $end)
    {
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) {
            return '';
        }

        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }


   public static function str_contains($haystack, $needle)
   {
       return strpos($haystack, $needle) !== false;
   }
}