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

    class ObserverCollection
    {
        /**
         * Array of observers
         *
         * @var array
         */
        protected $_observers;

        /**
         * Initializes observers
         *
         */
        public function __construct()
        {
            $this->_observers = array();
        }

        /**
         * Returns all observers in the collection
         *
         * @return array
         */
        public function getAllObservers()
        {
            return $this->_observers;
        }

        /**
         * Returns observer by its name
         *
         * @param string $observerName
         * @return Observer
         */
        public function getObserverByName($observerName)
        {
            return $this->_observers[$observerName];
        }

        /**
         * Adds an observer to the collection
         *
         * @param Observer $observer
         * @return ObserverCollection
         */
        public function addObserver(Observer $observer)
        {
            $this->_observers[$observer->getName()] = $observer;

            return $this;
        }

        /**
         * Removes an observer from the collection by its name
         *
         * @param string $observerName
         * @return ObserverCollection
         */
        public function removeObserverByName($observerName)
        {
            unset($this->_observers[$observerName]);

            return $this;
        }

        /**
         * Dispatches an event to all observers in the collection
         *
         * @param Event $event
         * @return ObserverCollection
         */
        public function dispatch(Event $event)
        {
            foreach ($this->_observers as $observer) {
                $observer->dispatch($event);
            }

            return $this;
        }
    }
