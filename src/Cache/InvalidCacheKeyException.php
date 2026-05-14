<?php
namespace ZealPHP\Cache;

class InvalidCacheKeyException extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}
