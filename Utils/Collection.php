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

    namespace Utils;

    use ArrayAccess;
    use IteratorAggregate;
    use ArrayIterator;

    class Collection implements ArrayAccess, IteratorAggregate
    {
        private $_nodes;
        private $_container;

        public function __construct($container)
        {
            $this->_nodes = [];
            $this->_container = $container;
        }

        public function getNodes()
        {
            return $this->_nodes;
        }

        /**
        * Implementation of IteratorAggregate::getIterator()
        */
        public function getIterator()
        {
            return new ArrayIterator($this->_nodes);
        }

        /**
        * Implementation of ArrayAccess:offsetSet()
        */
        public function offsetSet($key, $value)
        {
            $this->_nodes[$key] = $value;
        }

        /**
        * Implementation of ArrayAccess:offsetGet()
        */
        public function offsetGet($key)
        {
            return $this->_nodes[$key];
        }

        /**
        * Implementation of ArrayAccess:offsetUnset()
        */
        public function offsetUnset($key)
        {
            unset($this->_nodes[$key]);
        }

        /**
        * Implementation of ArrayAccess:offsetExists()
        */
        public function offsetExists($key)
        {
            return isset($this->_nodes[$key]);
        }

        /**
        * Adds a node to this node
        */
        public function add(Node $node)
        {
            $node->setParent($this->_container);

            // Set the Tree for the node
            if ($this->_container->getTree() instanceof Tree) {
                $node->setTree($this->_container->getTree());
            }

            $this->_nodes[$node->getId()] = $node;

            return $node;
        }

        public function delete($node)
        {
            if (isset($this->_nodes[$node->getId()])) {
                unset($this->_nodes[$node->getId()]);
            }

            return $this;
        }

        public function count()
        {
            return count($this->_nodes);
        }

        public function lastNode()
        {
            return !empty($this->_nodes) ? $this->_nodes[count($this->_nodes) - 1] : null;
        }

        public function searchById($nodeId)
        {
            if (isset($this->_nodes[$nodeId])) {
                return $this->_nodes[$nodeId];
            }

            return null;
        }
    }
