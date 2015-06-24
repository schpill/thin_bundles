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

    namespace Zelift;

    use Thin\Api\AbstractClient;
    use Thin\Exception as BadMethodCallException;

    class Apiclient extends AbstractClient
    {
        public function getTest()
        {
            $request    = $this->get('test/dummy');
            $response   = $request->send();

            return $response->getBody(true);
        }
    }

