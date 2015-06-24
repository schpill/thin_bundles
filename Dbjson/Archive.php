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

    class Archive
    {
        private $model, $db;

        public function __construct(Database $model)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            $this->model = $model;

            $this->db = Database::instance('system', 'archive');
        }

        public function make($sql, $delete = true)
        {
            $rows = $this->model->query($sql)->exec();

            if (count($rows)) {
                foreach ($rows as $row) {
                    $row['source_id']           = $row['id'];
                    $row['source_created_at']   = $row['created_at'];
                    $row['source_updated_at']   = $row['updated_at'];
                    $row['source_database']     = $this->model->db;
                    $row['source_table']        = $this->model->table;

                    unset($row['id']);
                    unset($row['created_at']);
                    unset($row['updated_at']);

                    $newRow = $this->db->create($row)->save();

                    if (true === $delete) {
                        $oldRow = $this->model->find($row['source_id'])->delete();
                    }
                }
            }

            return $this;
        }

        public function unmake($sql, $delete = true)
        {
            $sql = str_replace(['created_at', 'updated_at'], ['source_created_at', 'source_updated_at'], $sql);

            $rows = $this->db
            ->where('source_database = ' . $this->model->db)
            ->where('source_table = ' . $this->model->table)
            ->query($sql)
            ->exec();

            if (count($rows)) {
                foreach ($rows as $row) {
                    $id = $row['id'];
                    $newRowTab = [];

                    $newRowTab['id'] = $row['source_id'];
                    $newRowTab['created_at'] = $row['source_created_at'];
                    $newRowTab['updated_at'] = $row['source_updated_at'];

                    unset($row['id']);
                    unset($row['source_created_at']);
                    unset($row['source_updated_at']);
                    unset($row['source_database']);
                    unset($row['source_table']);

                    $newRowTab = array_merge($newRowTab, $row);

                    $newRow = $this->model->create($newRowTab)->save();

                    if (true === $delete) {
                        $oldRow = $this->db->find($id)->delete();
                    }
                }
            }

            return $this;
        }
    }
