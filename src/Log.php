<?php
/**
 * Created by PhpStorm.
 * User: 赵伟晨
 * Date: 2018/7/18
 * Time: 14:16
 */

namespace zwcway\Packagist;


class Log {
  protected static $debug = FALSE;

  public static function enableDebug()
  {
    self::$debug = TRUE;
  }

  public static function info() {
    fwrite(STDOUT, implode("\t", func_get_args()) . PHP_EOL);
  }

  public static function error() {
    fwrite(STDERR, implode("\t", func_get_args()) . PHP_EOL);
  }

  public static function terminal($code) {
    $msg = array_slice(func_get_args(), 1);
    fwrite(STDERR, implode("\t", $msg) . PHP_EOL);
    exit($code);
  }

  public static function debug() {
    self::$debug AND fwrite(STDOUT, implode('', func_get_args()) . PHP_EOL);
  }
}
