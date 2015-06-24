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

    use Thin\Utils;
    use Thin\Instance;
    use Dbjson\Dbjson as Database;

    class Session
    {
        private $bag;
        private $session;

        public function __construct(Database $model)
        {
            $key = 'dbjson_' . $model->db . '_' . APPLICATION_ENV . '_' . $model->table;

            $this->model    = $model;
            $this->session  = session($key);

            $sessionBag     = session($key)->getBag();
            $this->bag      = empty($sessionBag) ? [] : $sessionBag;
        }

        public static function instance(Database $model)
        {
            $key    = sha1($model->db . APPLICATION_ENV . $model->table);
            $has    = Instance::has('DbjsonSession', $key);

            if (true === $has) {
                return Instance::get('DbjsonSession', $key);
            } else {
                return Instance::make('DbjsonSession', $key, new self($model));
            }
        }

        public function commit()
        {
            $this->session->setBag($this->bag);
        }

        public function set($key, $value)
        {
            $this->bag[$key] = $value;
            $this->commit();

            return $this;
        }

        public function get($key, $default = null)
        {
            return isAke($this->bag, $key, $default);
        }

        public function has($key)
        {
            $dummy = Utils::token();

            return $dummy != $this->get($key, $dummy);
        }

        public function forget($key)
        {
            unset($this->bag[$key]);
            $this->commit();

            return $this;
        }
    }
