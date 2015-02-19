<?php
    namespace Fast;

    use Thin\Inflector;
    use Thin\Arrays;

    class Entity
    {
        public static function __callStatic($method, $args)
        {
            $db = Inflector::uncamelize($method);

            if (fnmatch('*_*', $db)) {
                list($database, $table) = explode('_', $db, 2);
            } else {
                $database   = SITE_NAME;
                $table      = $db;
            }

            if (empty($args)) {
                return Db::instance($database, $table);
            } elseif (count($args) == 1) {
                $id = Arrays::first($args);

                if (is_numeric($id)) {
                    return Db::instance($database, $table)->find($id);
                }
            }
        }
    }
