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

    class Container implements \ArrayAccess, \Countable, \IteratorAggregate
    {
        private $_data;

        /**
         * Constructor.
         *
         * @api
         */
        public function __construct()
        {
            $this->_data = [];
        }

        /**
         * Returns if a definition name exists.
         *
         * @param string $name The definition name.
         *
         * @return bool Returns if the definition name exists.
         *
         * @api
         */
        public function hasDefinition($name)
        {
            return isset($this->_data[$name]);
        }

        /**
         * Set a definition.
         *
         * @param string                       $name       The definition name.
         * @param Dbredis\Definition $definition The definition.
         *
         * @api
         */
        public function setDefinition($name, Definition $definition)
        {
            $this->_data[$name] = $definition;
        }

        /**
         * Set the _data.
         *
         * @param array $_data An array of _data.
         *
         * @api
         */
        public function setData(array $_data)
        {
            $this->_data = [];

            foreach ($_data as $name => $definition) {
                $this->setDefinition($name, $definition);
            }
        }

        /**
         * Returns a definition by name.
         *
         * @param string $name The definition name.
         *
         * @return Dbredis\Definition The definition.
         *
         * @throws \InvalidArgumentException If the definition does not exists.
         *
         * @api
         */
        public function getDefinition($name)
        {
            if (!$this->hasDefinition($name)) {
                throw new \InvalidArgumentException(sprintf('The definition "%s" does not exists.', $name));
            }

            return $this->_data[$name];
        }

        /**
         * Returns the _data.
         *
         * @return arary The _data.
         *
         * @api
         */
        public function getData()
        {
            return $this->_data;
        }

        /**
         * Remove a definition
         *
         * @param string $name The definition name
         *
         * @throws \InvalidArgumentException If the definition does not exists.
         *
         * @api
         */
        public function removeDefinition($name)
        {
            if (!$this->hasDefinition($name)) {
                throw new \InvalidArgumentException(sprintf('The definition "%s" does not exists.', $name));
            }

            unset($this->_data[$name]);
        }

        /**
         * Clear the _data.
         *
         * @api
         */
        public function clearData()
        {
            $this->_data = [];
        }

        /*
         * \ArrayAccess interface.
         */
        public function offsetExists($name)
        {
            return $this->hasDefinition($name);
        }

        public function offsetSet($name, $definition)
        {
            $this->setDefinition($name, $definition);
        }

        public function offsetGet($name)
        {
            return $this->getDefinition($name);
        }

        public function offsetUnset($name)
        {
            $this->removeDefinition($name);
        }

        /**
         * Returns the number of _data (implements the \Countable interface).
         *
         * @return integer The number of _data.
         *
         * @api
         */
        public function count()
        {
            return count($this->_data);
        }

        /**
         * Returns an \ArrayIterator with the _data (implements \IteratorAggregate interface).
         *
         * @return \ArrayIterator An \ArrayIterator with the _data.
         *
         * @api
         */
        public function getIterator()
        {
            return new \ArrayIterator($this->_data);
        }
    }
