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

    namespace Dbredis;

    use Thin\Inflector as I;
    use Thin\Arrays as A;

    class View
    {
        public static function make($db, $what)
        {
            if (is_string($what)) {
                $file = APPLICATION_PATH . DS . 'models' . DS . 'Bigdata' . DS . 'views' . DS . $db->db . DS . ucfirst(I::lower($db->table)) . DS . I::camelize($what) . '.php';

                if (files()->exists($file)) {
                    require_once $file;

                    $class = '\\Thin\\' . ucfirst(I::lower($db->db)) . ucfirst(I::lower($db->table)) . 'View';

                    return $class::make($db);
                } else {
                    return $db;
                }
            } elseif (A::is($what)) {
                $nameview   = 'view_' . $db->db . '_' . $db->table . '_' . sha1(serialize($what));
                $ageDb      = $db->getage();
                $viewDb     = Db::instance($db->db, $nameview);
                $ageView    = $db->getage();

                $exists     = strlen($db->cache()->get('dbRedis.views.' . $nameview)) ? true : false;

                if ($ageView < $ageDb || !$exists) {
                    $viewDb->getCollection()->remove();

                    foreach ($what as $wh) {
                        $op = 'AND';

                        if (count($wh) == 4) {
                            $op = $wh[3];
                            unset($wh[3]);
                        }

                        $db = $db->where($wh);
                    }

                    $res = $db->exec();

                    foreach ($res as $row) {
                        $viewDb->saveView($row);
                    }

                    $db->cache()->set('dbRedis.views.' . $nameview, 1);
                }

                return $viewDb;
            }
        }
    }
