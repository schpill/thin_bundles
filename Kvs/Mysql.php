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

    namespace Kvs;

    use PDO;
    use Thin\Instance;
    use Thin\Config as ConfigApp;
    use Dbjson\Config;

    class Mysql extends Sql
    {
        public static function connect()
        {
            $dsn = 'mysql:dbname=' . Config::get('mysql.dbname', ConfigApp::get('database.dbname', SITE_NAME)) . ';host=' . Config::get('mysql.host', ConfigApp::get('database.host', 'localhost'));

            $user       = Config::get('mysql.username', ConfigApp::get('database.username', 'root'));
            $password   = Config::get('mysql.password', ConfigApp::get('database.password', ''));

            $key        = sha1('KVSMYSQL');
            $has        = Instance::has('KvsMysql', $key);

            if (true === $has) {
                $instance = Instance::get('KvsMysql', $key);
                self::init($instance);

                return $instance;
            } else {
                $instance = Instance::make('KvsMysql', $key, new PDO($dsn, $user, $password));
                self::init($instance);

                return $instance;
            }
        }

        private static function init($db)
        {
            $q = "SHOW COLUMNS FROM kvs";
            $res = $db->query($q, PDO::FETCH_ASSOC);

            if (false === $res) {
                $q = "CREATE TABLE kvs (
                  `id` int(11) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  `env` varchar(32) NOT NULL,
                  `hkey` varchar(255) NOT NULL,
                  `val` text NULL,
                  `expire` int(11) NOT NULL
                ) COMMENT = 'Auto generated table kvs' ENGINE = 'InnoDB' COLLATE 'utf8_general_ci';";
                $db->query($q);
            }
        }
    }
