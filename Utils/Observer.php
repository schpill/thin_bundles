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

    class Observer extends Object
    {
        /**
         * Checkes the observer's event_regex against event's name
         *
         * @param Event $event
         * @return boolean
         */
        public function isValidFor(Event $event)
        {
            return $this->getEventName() === $event->getName();
        }

        /**
         * Dispatches an event to observer's callback
         *
         * @param Event $event
         * @return Observer
         */
        public function dispatch(Event $event)
        {
            if (!$this->isValidFor($event)) {
                return $this;
            }

            $callback = $this->getCallback();
            $this->setEvent($event);

            $_profilerKey = 'OBSERVER: ' . (
                is_object($callback[0])
                ? get_class($callback[0])
                : (string)$callback[0]
            ) . ' -> ' . $callback[1];

            Profiler::start($_profilerKey);

            call_user_func($callback, $this);

            Profiler::stop($_profilerKey);

            return $this;
        }

        public function getName()
        {
            return $this->getData('name');
        }

        public function setName($data)
        {
            return $this->setData('name', $data);
        }

        public function getEventName()
        {
            return $this->getData('event_name');
        }

        public function setEventName($data)
        {
            return $this->setData('event_name', $data);
        }

        public function getCallback()
        {
            return $this->getData('callback');
        }

        public function setCallback($data)
        {
            return $this->setData('callback', $data);
        }

        /**
         * Get observer event object
         *
         * @return Event
         */
        public function getEvent()
        {
            return $this->getData('event');
        }

        public function setEvent($data)
        {
            return $this->setData('event', $data);
        }
    }
