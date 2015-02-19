<?php
    namespace Kvs;

    use PDO;
    use SQLite3;
    use Thin\Instance;
    use Dbjson\Config;

    class Lite extends Sql
    {
        public static function connect()
        {
            $dbFile = Config::get('directory.store', STORAGE_PATH) . DS . 'kvs.db';

            self::init($dbFile);

            $key    = sha1('KVSLITE');
            $has    = Instance::has('KvsLite', $key);

            if (true === $has) {
                return Instance::get('KvsLite', $key);
            } else {
                return Instance::make('KvsLite', $key, new PDO('sqlite:' . $dbFile));
            }
        }

        private static function init($dbFile)
        {
            $db     = new SQLite3($dbFile);
            $q      = "SELECT * FROM sqlite_master WHERE type = 'table' AND name = 'kvs'";
            $res    = $db->query($q);

            if(false === $res->fetchArray()) {
                $db->exec('CREATE TABLE kvs (id INTEGER PRIMARY KEY AUTOINCREMENT, hkey, val, env, expire)');
            } else {
                umask(0000);
                chmod($dbFile, 0777);
            }
        }
    }
