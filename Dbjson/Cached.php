<?php
    /**
     * Thin is a swift Framework for PHP 5.4+
     *
     * @package    Thin
     * @version    1.0
     * @author     Gerald Plusquellec
     * @license    BSD License
     * @copyright  1996 - 2015 Gerald Plusquellec
     * @link       http://github.com/schpill/thin
     */

    namespace Dbjson;

    use \Dbjson\Dbjson as Database;
    use \Thin\Inflector;
    use \Thin\Event;

    class Cached
    {
        public static function make($name, Database $model, $overAge = false)
        {
            $name           = Inflector::urlize($name, '.');
            $ageChange      = $model->cache()->get(sha1($model->dir));
            $ageChange      = strlen($ageChange) ? $ageChange : 0;

            $hash           = "dbjson::cachedQueries::$model->db::$model->table";
            $cachedAge      = "dbjson::cachedQueries::$model->db::$model->table::$name::age";
            $ageCached      = $model->cache()->get($cachedAge);
            $ageCached      = strlen($ageCached) ? $ageCached : 0;

            $cached         = $model->cache()->hget($hash, $name);

            if (strlen($cached)) {
                if ($ageCached < $ageChange) {
                    if ($overAge) {
                        return new Cachedresults($cached);
                    } else {
                        $model->cache()->hdel($hash, $name);
                        $model->cache()->del($cachedAge);
                    }
                } else {
                    return new Cachedresults($cached);
                }
            }

            Event::listen(
                "$model->db.$model->table.put.in.cache",
                function ($collection) use ($model, $cachedAge, $hash, $name) {
                    $model->cache()->hset($hash, $name, serialize($collection));
                    $model->cache()->set($cachedAge, time());
                }
            );

            return $model;
        }

        public static function flush($db = null, $table = null, $name = null)
        {
            $db     = is_null($db)      ? '*' : $db;
            $table  = is_null($table)   ? '*' : $table;
            $name   = is_null($name)    ? '*' : Inflector::urlize($name, '.');

            if ($db != '*' && $table != '*') {
                $cache = Database::instance($db, $table)->cache();
            } else {
                $cache = Database::instance('auth', 'user')->cache();
            }

            $hashes = $cache->keys("dbjson::cachedQueries::$db::$table");
            $ages   = $cache->keys("dbjson::cachedQueries::$db::$table::$name::age");

            if (count($hashes)) {
                foreach ($hashes as $hash) {
                    $cache->del($hash);
                }
            }

            if (count($ages)) {
                foreach ($ages as $age) {
                    $cache->del($age);
                }
            }
        }
    }
