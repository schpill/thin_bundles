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

    namespace Mysqldump;

    use \Thin\Inflector;
    use \Thin\Arrays;
    use PDO;
    use PDOException;

    class Mysqldump
    {
        const MAXLINESIZE = 1000000;

        // This can be set both on constructor or manually
        public $host;
        public $user;
        public $pass;
        public $db;
        public $fileName;

        // Internal stuff
        private $tables = array();
        private $views = array();
        private $dbHandler;
        private $dbType;
        private $compressManager;
        private $typeAdapter;
        private $dumpSettings = array();
        private $pdoSettings = array();
        private $version;

        /**
         * Constructor of Mysqldump. Note that in the case of an SQLite database
         * connection, the filename must be in the $db parameter.
         *
         * @param string $db         Database name
         * @param string $user       SQL account username
         * @param string $pass       SQL account password
         * @param string $host       SQL server to connect to
         * @param string $type       SQL database type
         * @param array  $dumpSettings SQL database settings
         * @param array  $pdoSettings  PDO configured attributes
         *
         * @return null
         */
        public function __construct(
            $db = '',
            $user = '',
            $pass = '',
            $host = 'localhost',
            $type = 'mysql',
            $dumpSettings = array(),
            $pdoSettings = array()
        ) {
            $dumpSettingsDefault = array(
                'include-tables' => array(),
                'exclude-tables' => array(),
                'compress' => 'None',
                'no-data' => false,
                'add-drop-database' => false,
                'add-drop-table' => false,
                'single-transaction' => true,
                'lock-tables' => true,
                'add-locks' => true,
                'extended-insert' => true,
                'disable-foreign-keys-check' => false,
                'where' => '',
                'no-create-info' => false
            );

            $pdoSettingsDefault = array(PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false
            );

            $this->db = $db;
            $this->user = $user;
            $this->pass = $pass;
            $this->host = $host;
            $this->dbType = Inflector::lower($type);
            $this->pdoSettings = self::arrayReplaceRecursive($pdoSettingsDefault, $pdoSettings);
            $this->dumpSettings = self::arrayReplaceRecursive($dumpSettingsDefault, $dumpSettings);

            $diff = array_diff(array_keys($this->dumpSettings), array_keys($dumpSettingsDefault));

            if (count($diff)>0) {
                throw new Exception("Unexpected value in dumpSettings: (" . implode(",", $diff) . ")\n");
            }

        }

        /**
         * Custom arrayReplaceRecursive to be used if PHP < 5.3
         * Replaces elements from passed arrays into the first array recursively
         *
         * @param array $array1 The array in which elements are replaced
         * @param array $array2 The array from which elements will be extracted
         *
         * @return array Returns an array, or NULL if an error occurs.
         */
        public static function arrayReplaceRecursive($array1, $array2)
        {
            if (function_exists('arrayReplaceRecursive')) {
                return arrayReplaceRecursive($array1, $array2);
            }

            foreach ($array2 as $key => $value) {
                if (Arrays::is($value)) {
                    $array1[$key] = self::arrayReplaceRecursive($array1[$key], $value);
                } else {
                    $array1[$key] = $value;
                }
            }

            return $array1;
        }

        /**
         * Connect with PDO
         *
         * @return null
         */
        private function connect()
        {
            // Connecting with PDO
            try {
                switch ($this->dbType) {
                    case 'sqlite':
                        $this->dbHandler = new PDO("sqlite:" . $this->db, null, null, $this->pdoSettings);
                        break;
                    case 'mysql':
                    case 'pgsql':
                    case 'dblib':
                        $this->dbHandler = new PDO(
                            $this->dbType . ":host=" .
                            $this->host . ";dbname=" . $this->db,
                            $this->user,
                            $this->pass,
                            $this->pdoSettings
                        );
                        // Fix for always-unicode output
                        $this->dbHandler->exec("SET NAMES utf8");
                        // Store server version
                        $this->version = $this->dbHandler->getAttribute(PDO::ATTR_SERVER_VERSION);
                        break;
                    default:
                        throw new Exception("Unsupported database type (" . $this->dbType . ")");
                }
            } catch (PDOException $e) {
                throw new Exception(
                    "Connection to " . $this->dbType . " failed with message: " .
                    $e->getMessage()
                );
            }

            $this->dbHandler->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);
            $this->typeAdapter = TypeAdapterFactory::create($this->dbType, $this->dbHandler);
        }

        /**
         * Main call
         *
         * @param string $filename  Name of file to write sql dump to
         * @return null
         */
        public function start($filename = '')
        {
            // Output file can be redefined here
            if (!empty($filename)) {
                $this->fileName = $filename;
            }

            // We must set a name to continue
            if (empty($this->fileName)) {
                throw new Exception("Output file name is not set");
            }

            // Connect to database
            $this->connect();

            // Create a new compressManager to manage compressed output
            $this->compressManager = CompressManagerFactory::create($this->dumpSettings['compress']);

            $this->compressManager->open($this->fileName);

            // Formating dump file
            $this->compressManager->write($this->getHeader());

            if ($this->dumpSettings['add-drop-database']) {
                $this->compressManager->write($this->typeAdapter->add_drop_database($this->db));
            }

            // Listing all tables from database
            $this->tables = array();

            if (empty($this->dumpSettings['include-tables'])) {
                // include all tables for now, blacklisting happens later
                foreach ($this->dbHandler->query($this->typeAdapter->showTables($this->db)) as $row) {
                    array_push($this->tables, current($row));
                }
            } else {
                // include only the tables mentioned in include-tables
                foreach ($this->dbHandler->query($this->typeAdapter->showTables($this->db)) as $row) {
                    if (in_array(Arrays::first($row), $this->dumpSettings['include-tables'], true)) {
                        array_push($this->tables, Arrays::first($row));

                        $elem = array_search(
                            Arrays::first($row),
                            $this->dumpSettings['include-tables']
                        );

                        unset($this->dumpSettings['include-tables'][$elem]);
                    }
                }
            }

            // If there still are some tables in include-tables array, that means
            // that some tables weren't found. Give proper error and exit.
            if (0 < count($this->dumpSettings['include-tables'])) {
                $table = implode(",", $this->dumpSettings['include-tables']);

                throw new Exception("Table (" . $table . ") not found in database");
            }

            // Disable checking foreign keys
            if ($this->dumpSettings['disable-foreign-keys-check']) {
                $this->compressManager->write(
                    $this->typeAdapter->startDisableForeignKeysCheck()
                );
            }

            // Exporting tables one by one
            foreach ($this->tables as $table) {
                if (in_array($table, $this->dumpSettings['exclude-tables'], true)) {
                    continue;
                }

                $isTable = $this->getTableStructure($table);

                if (true === $isTable && false === $this->dumpSettings['no-data']) {
                    $this->listValues($table);
                }
            }

            // Exporting views one by one
            foreach ($this->views as $view) {
                $this->compressManager->write($view);
            }

            // Enable checking foreign keys if needed
            if ($this->dumpSettings['disable-foreign-keys-check']) {
                $this->compressManager->write(
                    $this->typeAdapter->endDisableForeignKeysCheck()
                );
            }

            $this->compressManager->close();
        }

        /**
         * Returns header for dump file
         *
         * @return string
         */
        private function getHeader()
        {
            // Some info about software, source and time
            $header = "-- mysqldump\n" .
                    "--\n" .
                    "-- Host: {$this->host}\tDatabase: {$this->db}\n" .
                    "-- ------------------------------------------------------\n";

            if (!empty($this->version)) {
                $header .= "-- Server version \t" . $this->version . "\n";
            }

            $header .= "-- Date: " . date('r') . "\n\n";

            return $header;
        }

        /**
         * Table structure extractor. Will return true if it's a table,
         * false if it's a view.
         *
         * @param string $tablename  Name of table to export
         * @return boolean
         */
        private function getTableStructure($tablename)
        {
            $stmt = $this->typeAdapter->showCreateTable($tablename);

            foreach ($this->dbHandler->query($stmt) as $r) {
                if (isset($r['Create Table'])) {
                    if (!$this->dumpSettings['no-create-info']) {
                        $this->compressManager->write(
                            "--\n" .
                            "-- Table structure for table `$tablename`\n" .
                            "--\n\n"
                        );

                        if ($this->dumpSettings['add-drop-table']) {
                            $this->compressManager->write("DROP TABLE IF EXISTS `$tablename`;\n\n");
                        }

                        $this->compressManager->write($r['Create Table'] . ";\n\n");
                    }

                    return true;
                }

                if (isset($r['Create View'])) {
                    if (!$this->dumpSettings['no-create-info']) {
                        $view  = "-- --------------------------------------------------------" .
                                "\n\n" .
                                "--\n" .
                                "-- Table structure for view `$tablename`\n" .
                                "--\n\n";
                        $view .= $r['Create View'] . ";\n\n";
                        $this->views[] = $view;
                    }

                    return false;
                }
            }

            throw new Exception("Error getting table structure, unknown output");
        }

        /**
         * Escape values with quotes when needed
         *
         * @param array $arr Array of strings to be quoted
         *
         * @return string
         */
        private function escape($arr)
        {
            $ret = array();

            foreach ($arr as $val) {
                if (is_null($val)) {
                    $ret[] = "NULL";
                } elseif (ctype_digit($val)) {
                    // faster than "(string) intval($val) === $val"
                    // but will quote negative integers (not a big deal IMHO)
                    $ret[] = $val;
                } else {
                    $ret[] = $this->dbHandler->quote($val);
                }
            }

            return $ret;
        }

        /**
         * Table rows extractor
         *
         * @param string $tablename  Name of table to export
         *
         * @return null
         */
        private function listValues($tablename)
        {
            $this->compressManager->write(
                "--\n" .
                "-- Dumping data for table `$tablename`\n" .
                "--\n\n"
            );

            if ($this->dumpSettings['single-transaction']) {
                $this->dbHandler->exec($this->typeAdapter->startTransaction());
            }

            if ($this->dumpSettings['lock-tables']) {
                $this->typeAdapter->lockTable($tablename);
            }

            if ($this->dumpSettings['add-locks']) {
                $this->compressManager->write($this->typeAdapter->startAddLockTable($tablename));
            }

            $onlyOnce = true;
            $lineSize = 0;
            $stmt = "SELECT * FROM `$tablename`";

            if ($this->dumpSettings['where']) {
                $stmt .= " WHERE {$this->dumpSettings['where']}";
            }

            $resultSet = $this->dbHandler->query($stmt);
            $resultSet->setFetchMode(PDO::FETCH_NUM);

            foreach ($resultSet as $r) {
                $vals = $this->escape($r);

                if ($onlyOnce || !$this->dumpSettings['extended-insert']) {
                    $lineSize += $this->compressManager->write(
                        "INSERT INTO `$tablename` VALUES (" . implode(",", $vals) . ")"
                    );

                    $onlyOnce = false;
                } else {
                    $lineSize += $this->compressManager->write(",(" . implode(",", $vals) . ")");
                }
                if (($lineSize > self::MAXLINESIZE) ||
                        !$this->dumpSettings['extended-insert']) {
                    $onlyOnce = true;
                    $lineSize = $this->compressManager->write(";\n");
                }
            }

            $resultSet->closeCursor();

            if (!$onlyOnce) {
                $this->compressManager->write(";\n");
            }

            if ($this->dumpSettings['add-locks']) {
                $this->compressManager->write($this->typeAdapter->endAddLockTable($tablename));
            }

            if ($this->dumpSettings['single-transaction']) {
                $this->dbHandler->exec($this->typeAdapter->commitTransaction());
            }

            if ($this->dumpSettings['lock-tables']) {
                $this->typeAdapter->unlockTable($tablename);
            }
        }
    }

    /**
     * Enum with all available compression methods
     *
     */
    abstract class CompressMethod
    {
        public static $enums = array(
            "None",
            "Gzip",
            "Bzip2"
        );

        /**
         * @param string $c
         * @return boolean
         */
        public static function isValid($c)
        {
            return in_array($c, self::$enums);
        }
    }

    abstract class CompressManagerFactory
    {
        /**
         * @param string $c
         * @return CompressBzip2|CompressGzip|CompressNone
         */
        public static function create($c)
        {
            $c = ucfirst(strtolower($c));

            if (! CompressMethod::isValid($c)) {
                throw new Exception("Compression method ($c) is not defined yet");
            }

            $method =  __NAMESPACE__ . "\\" . "Compress" . $c;

            return new $method;
        }
    }

    class CompressBzip2 extends CompressManagerFactory
    {
        private $fileHandler = null;

        public function __construct()
        {
            if (!function_exists("bzopen")) {
                throw new Exception("Compression is enabled, but bzip2 lib is not installed or configured properly");
            }
        }

        public function open($filename)
        {
            $this->fileHandler = bzopen($filename . ".bz2", "w");

            if (false === $this->fileHandler) {
                throw new Exception("Output file is not writable");
            }

            return true;
        }

        public function write($str)
        {
            if (false === ($bytesWritten = bzwrite($this->fileHandler, $str))) {
                throw new Exception("Writting to file failed! Probably, there is no more free space left?");
            }

            return $bytesWritten;
        }

        public function close()
        {
            return bzclose($this->fileHandler);
        }
    }

    class CompressGzip extends CompressManagerFactory
    {
        private $fileHandler = null;

        public function __construct()
        {
            if (! function_exists("gzopen")) {
                throw new Exception("Compression is enabled, but gzip lib is not installed or configured properly");
            }
        }

        public function open($filename)
        {
            $this->fileHandler = gzopen($filename . ".gz", "wb");
            if (false === $this->fileHandler) {
                throw new Exception("Output file is not writable");
            }

            return true;
        }

        public function write($str)
        {
            if (false === ($bytesWritten = gzwrite($this->fileHandler, $str))) {
                throw new Exception("Writting to file failed! Probably, there is no more free space left?");
            }

            return $bytesWritten;
        }

        public function close()
        {
            return gzclose($this->fileHandler);
        }
    }

    class CompressNone extends CompressManagerFactory
    {
        private $fileHandler = null;

        public function open($filename)
        {
            $this->fileHandler = fopen($filename, "wb");

            if (false === $this->fileHandler) {
                throw new Exception("Output file is not writable");
            }

            return true;
        }

        public function write($str)
        {
            if (false === ($bytesWritten = fwrite($this->fileHandler, $str))) {
                throw new Exception("Writting to file failed! Probably, there is no more free space left?");
            }

            return $bytesWritten;
        }

        public function close()
        {
            return fclose($this->fileHandler);
        }
    }

    /**
     * Enum with all available TypeAdapter implementations
     *
     */
    abstract class TypeAdapter
    {
        public static $enums = array(
            "Sqlite",
            "Mysql"
        );

        /**
         * @param string $c
         * @return boolean
         */
        public static function isValid($c)
        {
            return Arrays::in($c, self::$enums);
        }
    }

    /**
     * TypeAdapter Factory
     *
     */
    abstract class TypeAdapterFactory
    {
        /**
         * @param string $c Type of database factory to create (Mysql, Sqlite,...)
         * @param PDO $dbHandler
         */

        public static function create($c, $dbHandler = null)
        {
            $c = ucfirst(Inflector::lower($c));

            if (!TypeAdapter::isValid($c)) {
                throw new Exception("Database type support for ($c) not yet available");
            }

            $method =  __NAMESPACE__ . "\\" . "TypeAdapter" . $c;

            return new $method($dbHandler);
        }

        public function showCreateTable($tablename)
        {
            return "SELECT tbl_name as 'Table', sql as 'Create Table' " .
                "FROM sqlite_master " .
                "WHERE type = 'table' AND tbl_name = '$tablename'";
        }

        public function showTables()
        {
            return "SELECT tbl_name FROM sqlite_master where type = 'table'";
        }

        public function startTransaction()
        {
            return "BEGIN EXCLUSIVE";
        }

        public function commitTransaction()
        {
            return "COMMIT";
        }

        public function lockTable()
        {
            return "";
        }

        public function unlockTable()
        {
            return "";
        }

        public function startAddLockTable()
        {
            return "\n";
        }

        public function endAddLockTable()
        {
            return "\n";
        }

        public function startDisableForeignKeysCheck()
        {
            return "\n";
        }

        public function endDisableForeignKeysCheck()
        {
            return "\n";
        }

        public function add_drop_database()
        {
            return "\n";
        }
    }

    class TypeAdapterPgsql extends TypeAdapterFactory
    {
    }

    class TypeAdapterDblib extends TypeAdapterFactory
    {
    }

    class TypeAdapterSqlite extends TypeAdapterFactory
    {
    }

    class TypeAdapterMysql extends TypeAdapterFactory
    {

        private $dbHandler = null;

        public function __construct ($dbHandler)
        {
            $this->dbHandler = $dbHandler;
        }

        public function showCreateTable($tableName)
        {
            return "SHOW CREATE TABLE `$tableName`";
        }

        public function showTables()
        {
            if (func_num_args() != 1) {
                return "";
            }

            $args = func_get_args();

            return "SELECT TABLE_NAME AS tbl_name " .
                "FROM INFORMATION_SCHEMA.TABLES " .
                "WHERE TABLE_TYPE='BASE TABLE' AND TABLE_SCHEMA='${args[0]}'";
        }

        public function startTransaction()
        {
            return "SET GLOBAL TRANSACTION ISOLATION LEVEL REPEATABLE READ; " .
                "START TRANSACTION";
        }

        public function commitTransaction()
        {
            return "COMMIT";
        }

        public function lockTable()
        {
            if (func_num_args() != 1) {
                return "";
            }

            $args = func_get_args();
            //$tableName = $args[0];
            //return "LOCK TABLES `$tableName` READ LOCAL";

            return $this->dbHandler->exec("LOCK TABLES `${args[0]}` READ LOCAL");

        }

        public function unlockTable()
        {
            return $this->dbHandler->exec("UNLOCK TABLES");
        }

        public function startAddLockTable()
        {
            if (func_num_args() != 1) {
                return "";
            }

            $args = func_get_args();

            return "LOCK TABLES `${args[0]}` WRITE;\n";
        }

        public function endAddLockTable()
        {
            return "UNLOCK TABLES;\n";
        }

        public function startDisableForeignKeysCheck()
        {
            return "-- Ignore checking of foreign keys\n" .
                "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        }

        public function endDisableForeignKeysCheck()
        {
            return "\n-- Unignore checking of foreign keys\n" .
                "SET FOREIGN_KEY_CHECKS = 1; \n\n";
        }

        public function add_drop_database()
        {
            if (func_num_args() != 1) {
                 return "";
            }

            $args = func_get_args();

            $ret = "/*!40000 DROP DATABASE IF EXISTS `${args[0]}`*/;\n";

            $resultSet = $this->dbHandler->query("SHOW VARIABLES LIKE 'character_set_database';");
            $characterSet = $resultSet->fetchColumn(1);
            $resultSet->closeCursor();

            $resultSet = $this->dbHandler->query("SHOW VARIABLES LIKE 'collation_database';");
            $collationDb = $resultSet->fetchColumn(1);
            $resultSet->closeCursor();

            $ret .= "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `${args[0]}`".
                " /*!40100 DEFAULT CHARACTER SET " . $characterSet .
                " COLLATE " . $collationDb . "*/;\n" .
                "USE `${args[0]}`;\n\n";

            return $ret;
        }
    }
