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

    namespace Mongodm;

    use Thin\Arrays;

    class Mongodm
    {
        /**
         * Database instances
         *
         * @var array
         */
        public static $instances = [];

        /**
         * Database config
         *
         * @var array
         */
        public static $config = [];

        /**
         * Load instance
         *
         * @param string     $name   name
         * @param array|null $config config
         *
         * @static
         *
         * @return Mongodm
        */
        public static function instance($name = 'default', $config = null)
        {
            if (!isset(static::$instances[$name])) {
                if ($config === null) {
                    // Load the configuration for this database
                    $config = self::config($name);
                }

                static::$instances[$name] = new self($name, $config);
            }

            return static::$instances[$name];
        }

        /**
         * Instance name
         *
         * @var string
         */
        protected $_name;

        /**
         * Connected
         *
         * @var bool
         */
        protected $_isConnect = false;

        /**
         * Raw server connection
         *
         * @var \Mongo
         */
        protected $_connection;

        /**
         * Raw database connection
         *
         * @var \Mongo Database
         */
        protected $_db;

        /**
         * Local config
         *
         * @var array
         */
        protected $_config;

        /**
         * @param string $name   name
         * @param array  $config config
         */
        public function __construct($name, $config = [])
        {
            $this->_name = $name;

            $this->_config = $config;
            /* Store the database instance */
            self::$instances[$name] = $this;

            $this->connect();
        }

        /**
         * Destructor
         */
        final public function __destruct()
        {
            $this->disconnect();
        }

        /**
         * To string
         *
         * @return string
         */
        final public function __toString()
        {
            return $this->_name;
        }

        /**
         * Connect to MongoDB, select database
         *
         * @throws \Exception
         * @return bool
         */
        public function connect()
        {
            if ($this->_connection) {
                return true;
            }
            /**
             * Add required variables
             * Clear the connection parameters for security
             */
            $config = array(
                'hostnames'  => 'localhost:27017',
                'database'  => SITE_NAME,
            );


            /* Add Username & Password to server string */
            if (isset($config['username']) && isset($config['password'])) {
                $config['hostnames'] = $config['username'] . ':' . $config['password'] . '@' . $config['hostnames'] . '/' . $config['database'];
            }

            /* Add required 'mongodb://' prefix */
            if (strpos($config['hostnames'], 'mongodb://') !== 0) {
                $config['hostnames'] = 'mongodb://' . $config['hostnames'];
            }

            if (!isset($config['options']) || !Arrays::is($config['options'])) {
                $config['options'] = [];
            }

            /* Create connection object, attempt to connect */
            $config['options']['connect'] = false;

            $this->_connection = new \MongoClient($config['hostnames'], $config['options']);

            /* Try connect */
            try {
                $this->_connection->connect();
            } catch (\MongoConnectionException $e) {
                throw new \Exception('Unable to connect to MongoDB server at ' . $config['hostnames']);
            }

            if (!isset($config['database'])) {
                throw new \Exception('No database specified in MangoDB Config');
            }

            $this->_db = $this->_connection->selectDB($config['database']);

            if(!$this->_db instanceof \MongoDB) {
                throw new \Exception('Unable to connect to database :: $_db is ' . gettype($this->_db));
            }

            $this->_isConnect = true;

            return true;
        }

        /**
         * Get ref
         *
         * @param array $ref ref
         *
         * @return \MongoDBRef
         */
        public function getRef(array $ref)
        {
            return $this->_connection->getDBRef($ref);

        }

        /**
         * Disconnect from MongoDB
         *
         * @return null
         */
        public function disconnect()
        {
            if ($this->_connection) {
                try {
                    $this->_connection->close();
                } catch (\Exception $e) {
                    /* TODO */
                }
            }

            $this->_db = $this->_connection = null;
        }

        /**
         * Get db
         *
         * @return MongoDB || null
         */
        public function getDB()
        {
            return $this->_db;
        }

        /* Database Management */

        /**
         * Last error
         *
         * @return string|null
         */
        public function last_error()
        {
            return $this->_isConnect
                ? $this->_db->lastError()
                : null;
        }

        /**
         * prev error
         *
         * @return string|null
         */
        public function prev_error()
        {
            return $this->_isConnect
                ? $this->_db->prevError()
                : null;
        }

        /**
         * reset error
         *
         * @return string|null
         */
        public function reset_error()
        {
            return $this->_isConnect
                ? $this->_db->resetError()
                : null;
        }

        /**
         * command
         *
         * @param array $data data
         *
         * @return string|null
         */
        public function command(array $data)
        {
            return $this->_call('command', [], $data);
        }

        /**
         * distinct
         *
         * @param array $data data
         *
         * @return string|null
         */
        public function distinct(array $data)
        {
            return $this->command($data);
        }

        /**
         * execute
         *
         * @param string $code code
         * @param array  $args array
         *
         * @return string|null
         */
        public function execute($code, array $args = [])
        {
            return $this->_call(
                'execute', array(
                    'code' => $code,
                    'args' => $args
                )
            );
        }

        /* Collection management */

        /**
         * create collection
         *
         * @param string $name   name
         * @param bool   $capped capped
         * @param int    $size   size
         * @param int    $max    max
         *
         * @return string|null
         */
        public function create_collection($name, $capped = false, $size = 0, $max = 0)
        {
            return $this->_call(
                'create_collection', array(
                    'name'    => $name,
                    'capped'  => $capped,
                    'size'    => $size,
                    'max'     => $max
                )
            );
        }

        /**
         * create collection
         *
         * @param string $name name
         *
         * @return string|null
         */
        public function drop_collection($name)
        {
            return $this->_call(
                'drop_collection', array(
                    'name' => $name
                )
            );
        }

        /**
         * ensure index
         *
         * @param string $collection_name name
         * @param string $keys            keys
         * @param array  $options         options
         *
         * @return string|null
         */
        public function ensure_index($collection_name, $keys, $options = [])
        {
            return $this->_call(
                'ensure_index', array(
                    'collection_name' => $collection_name,
                    'keys'            => $keys,
                    'options'         => $options
                )
            );
        }

        /* Data Management */

        /**
         * batch insert
         *
         * @param string $collection_name collection name
         * @param array  $a               a
         *
         * @return string|null
         */
        public function batch_insert($collection_name, array $a)
        {
            return $this->_call(
                'batch_insert', array(
                    'collection_name' => $collection_name
                ), $a
            );
        }

        /**
         * count
         *
         * @param string $collection_name collection name
         * @param array  $query           query
         *
         * @return mixed
         */
        public function count($collection_name, array $query = [])
        {
            return $this->_call(
                'count', array(
                    'collection_name' => $collection_name,
                    'query'           => $query
                )
            );
        }

        /**
         * find one
         *
         * @param string $collection_name collection name
         * @param array  $query           query
         * @param array  $fields          fields
         *
         * @return mixed
         */
        public function find_one($collection_name, array $query = [], array $fields = [])
        {
            return $this->_call(
                'find_one', array(
                    'collection_name' => $collection_name,
                    'query'           => $query,
                    'fields'          => $fields
                )
            );
        }

        /**
         * find
         *
         * @param string $collection_name collection name
         * @param array  $query           query
         * @param array  $fields          fields
         *
         * @return mixed
         */
        public function find($collection_name, array $query = [], array $fields = [])
        {
            return $this->_call(
                'find', array(
                    'collection_name' => $collection_name,
                    'query'           => $query,
                    'fields'          => $fields
                )
            );
        }

        /**
         * group
         *
         * @param string $collection_name collection name
         * @param array  $keys            keys
         * @param array  $initial         initial
         * @param array  $reduce          reduce
         * @param array  $condition       condition
         *
         * @return mixed
         */
        public function group($collection_name, $keys, array $initial, $reduce, array $condition = [])
        {
            return $this->_call(
                'group', array(
                    'collection_name' => $collection_name,
                    'keys'            => $keys,
                    'initial'         => $initial,
                    'reduce'          => $reduce,
                    'condition'       => $condition
                )
            );
        }

        /**
         * update
         *
         * @param string $collection_name collection name
         * @param array  $criteria        criteria
         * @param array  $newObj          new obj
         * @param array  $options         options
         *
         * @return mixed
         */
        public function update($collection_name, array $criteria, array $newObj, $options = [])
        {
            return $this->_call(
                'update', array(
                    'collection_name' => $collection_name,
                    'criteria'        => $criteria,
                    'options'         => $options
                ), $newObj
            );
        }

        /**
         * insert
         *
         * @param string $collection_name collection name
         * @param array  $a               a
         * @param array  $options         options
         *
         * @return mixed
         */
        public function insert($collection_name, array $a, $options = [])
        {
            return $this->_call(
                'insert', array(
                    'collection_name' => $collection_name,
                    'options'         => $options
                ), $a
            );
        }

        /**
         * remove
         *
         * @param string $collection_name collection name
         * @param array  $criteria        criteria
         * @param array  $options         options
         *
         * @return mixed
         */
        public function remove($collection_name, array $criteria, $options = [])
        {
            return $this->_call(
                'remove', array(
                    'collection_name' => $collection_name,
                    'criteria'        => $criteria,
                    'options'         => $options
                )
            );
        }

        /**
         * save
         *
         * @param string $collection_name collection name
         * @param array  $a               a
         * @param array  $options         options
         *
         * @return mixed
         */
        public function save($collection_name, array $a, $options = [])
        {
            return $this->_call(
                'save', array(
                    'collection_name' => $collection_name,
                    'options'         => $options
                ), $a
            );
        }

        /* File management */

        /**
         * grid fs
         *
         * @param mixed|null $arg1 arg1
         *
         * @return mixed
         */
        public function gridFS($arg1 = null)
        {
            try {
                $this->_isConnect || $this->connect();
            } catch (\Exception $e) {
                throw $e;
            }

            if ( ! isset($arg1)) {
                $arg1 = isset($this->_config['gridFS']['arg1'])
                ? $this->_config['gridFS']['arg1']
                : 'fs';
            }

            return $this->_db->getGridFS($arg1);
        }

        /**
         * get file
         *
         * @param array $criteria criteria
         *
         * @return mixed
         */
        public function get_file(array $criteria = [])
        {
            return $this->_call(
                'get_file', array(
                    'criteria' => $criteria
                )
            );
        }

        /**
         * get files
         *
         * @param array $query  query
         * @param array $fields fields
         *
         * @return mixed
         */
        public function get_files(array $query = [], array $fields = [])
        {
            return $this->_call(
                'get_files', array(
                    'query'  => $query,
                    'fields' => $fields
                )
            );
        }

        /**
         * set file bytes
         *
         * @param mixed $bytes   bytes
         * @param array $extra   extra
         * @param array $options options
         *
         * @return mixed
         */
        public function set_file_bytes($bytes, array $extra = [], array $options = [])
        {
            return $this->_call(
                'set_file_bytes', array(
                    'bytes'   => $bytes,
                    'extra'   => $extra,
                    'options' => $options
                )
            );
        }

        /**
         * set file
         *
         * @param string $filename filename
         * @param array  $extra    extra
         * @param array  $options  options
         *
         * @return mixed
         */
        public function set_file($filename, array $extra = [], array $options = [])
        {
            return $this->_call(
                'set_file', array(
                    'filename' => $filename,
                    'extra'    => $extra,
                    'options'  => $options
                )
            );
        }

        /**
         * remove file
         *
         * @param array $criteria criteria
         * @param array $options  options
         *
         * @return mixed
         */
        public function remove_file(array $criteria = [], array $options = [])
        {
            return $this->_call(
                'remove_file', array(
                    'criteria' => $criteria,
                    'options'  => $options
                )
            );
        }


        /**
         * _call
         *
         * @param string     $command   command
         * @param array      $arguments arguments
         * @param array|null $values    values
         *
         * @return mixed
         */
        protected function _call($command, array $arguments = [], array $values = null)
        {
            try {
                $this->_isConnect || $this->connect();
            } catch (\Exception $e) {
                throw $e;
            }

            extract($arguments);

            if (isset($collection_name)) {
                $c = $this->_db->selectCollection($collection_name);
            }

            switch ($command) {
                case 'ensure_index':
                    $r = $c->ensureIndex($keys, $options);
                    break;
                case 'create_collection':
                    $r = $this->_db->createCollection($name, $capped, $size, $max);
                    break;
                case 'drop_collection':
                    $r = $this->_db->dropCollection($name);
                    break;
                case 'command':
                    $r = $this->_db->command($values);
                    break;
                case 'execute':
                    $r = $this->_db->execute($code, $args);
                    break;
                case 'batch_insert':
                    $r = $c->batchInsert($values);
                    break;
                case 'count':
                    $r = $c->count($query);
                    break;
                case 'find_one':
                    $r = $c->findOne($query, $fields);
                    break;
                case 'find':
                    $r = $c->find($query, $fields);
                    break;
                case 'group':
                    $r = $c->group($keys, $initial, $reduce, $condition);
                    break;
                case 'update':
                    $r = $c->update($criteria, $values, $options);
                    break;
                case 'insert':
                    $r = $c->insert($values, $options);

                    return $values;
                    break;
                case 'remove':
                    $r = $c->remove($criteria, $options);
                    break;
                case 'save':
                    $r = $c->save($values, $options);
                    break;
                case 'get_file':
                    $r = $this->gridFS()->findOne($criteria);
                    break;
                case 'get_files':
                    $r = $this->gridFS()->find($query, $fields);
                    break;
                case 'set_file_bytes':
                    $r = $this->gridFS()->storeBytes($bytes, $extra, $options);
                    break;
                case 'set_file':
                    $r = $this->gridFS()->storeFile($filename, $extra, $options);
                    break;
                case 'remove_file':
                    $r = $this->gridFS()->remove($criteria, $options);
                    break;
                case 'aggregate':
                    $r = call_user_func_array(array($c, 'aggregate'), $ops);
                    break;
            }

            return $r;
        }

        /**
         * method
         *
         * @param array $config config
         *
         * @return null
         */
        public static function setConfig($config)
        {
            self::$config = $config;
        }

        /**
         * set config block
         *
         * @param string $block  block
         * @param array  $config config
         *
         * @return null
         */
        public static function setConfigBlock ($block = 'default', $config = [])
        {
            self::$config[$block] = $config;
        }

        /**
         * config
         *
         * @param string $config_block config_block
         *
         * @throws \Exception
         * @return null
         */
        public static function config($config_block)
        {
            if (!empty(static::$config)) {
                return static::$config[$config_block];
            }
        }

        /**
         * @return array
         */
        public function aggregate()
        {
            $ops = func_get_args();
            $collection_name = array_shift($ops);

            return $this->_call('aggregate',
                array(
                    'collection_name'   => $collection_name,
                    'ops'               => $ops
                )
            );
        }
    }
