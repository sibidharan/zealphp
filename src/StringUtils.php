<?php
namespace ZealPHP;

/**
 * StringUtils provides utility functions for common string operations.
 *
 * Includes methods for checking prefixes, suffixes, substrings, and extracting text.
 */
class StringUtils
{
    /**
     * Check if a string begins with a given substring.
     *
     * @param string $haystack The string to search in.
     * @param string $needle   The substring to search for at the start.
     * @return bool True if $haystack starts with $needle.
     */
    public static function str_starts_with($haystack, $needle)
    {
        $length = strlen($needle);
        return (substr($haystack, 0, $length) === $needle);
    }

    /**
     * Check if a string ends with a given substring.
     *
     * @param string $haystack The string to search in.
     * @param string $needle   The substring to search for at the end.
     * @return bool True if $haystack ends with $needle.
     */
    public static function str_ends_with($haystack, $needle)
    {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }
        return (substr($haystack, -$length) === $needle);
    }

    /**
    * A general method used to ger the string between two index locations.
    * @param  String $string
    * @param  Integer $start
    * @param  Integer $end
    * @return String         The sliced string.
    */
    public static function get_string_between($string, $start, $end)
    {
        $string = ' ' . $string;
        $ini = strpos($string, (int)$start);
        if ($ini == 0) {
            return '';
        }

        $ini += strlen((int)$start);
        $len = strpos($string, (int)$end, $ini) - $ini;
        return substr($string, $ini, $len);
    }


   /**
    * Determine if a string contains a given substring.
    *
    * @param string $haystack The string to search in.
    * @param string $needle   The substring to search for.
    * @return bool True if $needle is found in $haystack.
    */
   public static function str_contains($haystack, $needle)
   {
       return strpos($haystack, $needle) !== false;
   }
}
