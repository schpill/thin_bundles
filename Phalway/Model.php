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

    namespace Phalway;

    use Phalcon\DI;
    use Phalcon\Mvc\Model as PhalconModel;
    use Phalcon\Mvc\Model\Query\Builder;


    abstract class AbstractModel extends PhalconModel
    {
        /**
         * Get table name.
         *
         * @return string
         */
        public static function getTableName()
        {
            $reader         = DI::getDefault()->get('annotations');
            $reflector      = $reader->get(get_called_class());
            $annotations    = $reflector->getClassAnnotations();

            return $annotations->get('Source')->getArgument(0);
        }

        /**
         * Find method overload.
         * Get entities according to some condition.
         *
         * @param string      $condition Condition string.
         * @param array       $params    Condition params.
         * @param string|null $order     Order by field name.
         * @param string|null $limit     Selection limit.
         *
         * @return PhalconModel\ResultsetInterface
         */
        public static function get($condition, $params, $order = null, $limit = null)
        {
            $condition  = vsprintf($condition, $params);
            $parameters = [$condition];

            if ($order) {
                $parameters['order'] = $order;
            }

            if ($limit) {
                $parameters['limit'] = $limit;
            }

            return self::find($parameters);
        }

        /**
         * FindFirst method overload.
         * Get entity according to some condition.
         *
         * @param string      $condition Condition string.
         * @param array       $params    Condition params.
         * @param string|null $order     Order by field name.
         *
         * @return AbstractModel
         */
        public static function getFirst($condition, $params, $order = null)
        {
            $condition = vsprintf($condition, $params);
            $parameters = [$condition];

            if ($order) {
                $parameters['order'] = $order;
            }

            return self::findFirst($parameters);
        }

        /**
         * Get builder associated with table of this model.
         *
         * @param string|null $tableAlias Table alias to use in query.
         *
         * @return Builder
         */
        public static function getBuilder($tableAlias = null)
        {
            $builder    = new Builder();
            $table      = get_called_class();

            if (!$tableAlias) {
                $builder->from($table);
            } else {
                $builder->addFrom($table, $tableAlias);
            }

            return $builder;
        }

        /**
         * Get identity.
         *
         * @return mixed
         */
        public function getId()
        {
            if (property_exists($this, 'id')) {
                return $this->id;
            }

            $primaryKeys = $this->getDI()->get('modelsMetadata')->getPrimaryKeyAttributes($this);

            switch (count($primaryKeys)) {
                case 0:
                    return null;
                    break;
                case 1:
                    return $this->{$primaryKeys[0]};
                    break;
                default:
                    return array_intersect_key(
                        get_object_vars($this),
                        array_flip($primaryKeys)
                    );
            }
        }
    }
