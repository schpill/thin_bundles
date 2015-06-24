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

    use PHPSQLParser\PHPSQLParser as SQL;
    use Thin\Arrays;

    class Query
    {
        public function __construct($sql)
        {
            $parse  = new SQL($sql);
            $query  = $parse->parsed;
            $parsed = array_keys($query);

            if (Arrays::first($parsed) == 'SELECT') {
                return $this->select($query);
            } elseif (Arrays::first($parsed) == 'UPDATE') {
                return $this->update($query);
            } elseif (Arrays::first($parsed) == 'DELETE') {
                return $this->delete($query);
            } elseif (Arrays::first($parsed) == 'INSERT') {
                return $this->insert($query);
            }
        }

        private function select($query)
        {
            $orm = $this->getOrm(isAke($query, 'FROM', []));
            dd($query);
        }

        private function update($query)
        {
            $seg = isAke($query, 'UPDATE', []);
            $orm = $this->getOrm([Arrays::last($seg)]);
            dd($orm);
        }

        private function delete($query)
        {
            $orm = $this->getOrm(isAke($query, 'FROM', []));
            dd($orm);
        }

        private function insert($query)
        {
            $seg = isAke($query, 'INSERT', []);
            $orm = $this->getOrm([Arrays::last($seg)]);
            dd($orm);
        }

        private function getOrm($seg)
        {
            if (!count($seg)) {
                throw new \Exception('Query is invalid.');
            }

            $seg = Arrays::first($seg);

            $table = isAke($seg, 'table', false);

            if (!$table) {
                throw new \Exception('Query is invalid.');
            }

            if (fnmatch('*.*', $table)) {
                list($database, $table) = explode('.', $table);
            } else {
                $database = SITE_NAME;
            }

            return jdb($database, $table);
        }
    }
