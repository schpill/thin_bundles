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

    use Thin\Container;
    use Thin\Instance;
    use Thin\Arrays;
    use Thin\Utils;
    use Dbjson\Dbjson as Db;

    class Model
    {
        private $db, $row;

        public static function instance(Db $db, Container $row)
        {
            $key = sha1($db->db . $db->table . serialize($row->assoc()));
            $has    = Instance::has('DbjsonModel', $key);

            if (true === $has) {
                return Instance::get('DbjsonModel', $key);
            } else {
                return Instance::make('DbjsonModel', $key, new self($db, $row));
            }
        }

        public function __construct(Db $db, Container $row)
        {
            $this->db   = $db;
            $this->row  = $row;
        }

        public function db()
        {
            return $this->db;
        }

        public function now()
        {
            return time();
        }
    }
