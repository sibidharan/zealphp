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


   public static function str_contains($haystack, $needle)
   {
       return strpos($haystack, $needle) !== false;
   }
}