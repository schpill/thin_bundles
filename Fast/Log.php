<?php
    namespace Fast;

    use Thin\Instance;
    use Thin\Arrays;

    class Log
    {
        private $db, $ns;

        public function __construct($ns)
        {
            $this->ns = $ns;
            $this->db = Db::instance('core', 'log');
        }

        public function write($status, $message)
        {
            $this->db->create([
                'ns'        => $this->ns,
                'date'      => date('Y-m-d H:i:s'),
                'status'    => mb_strtoupper($status),
                'message'   => $message
            ])->save();

            return $this;
        }

        public function exception($e)
        {
            return $this->write('error', $this->exceptionLine($e));
        }

        protected function exceptionLine($e)
        {
            return $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
        }

        public function __call($method, $parameters)
        {
            return $this->write($method, Arrays::first($parameters));
        }

        public static function instance($ns)
        {
            $key    = sha1($ns);
            $has    = Instance::has('FastDbLog', $key);

            if (true === $has) {
                return Instance::get('FastDbLog', $key);
            } else {
                return Instance::make('FastDbLog', $key, new self($ns));
            }
        }
    }
