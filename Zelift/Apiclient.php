<?php
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

