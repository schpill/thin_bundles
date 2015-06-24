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

	use ErrorException;
	use Thin\Arrays;
	use Symfony\Component\Debug\ErrorHandler;
	use Symfony\Component\Debug\ExceptionHandler;
	use Symfony\Component\Console\Output\ConsoleOutput;
	use Symfony\Component\Debug\Exception\FatalErrorException;

	class Exception
	{
		/**
		 * Bootstrap the given application.
		 *
		 * @return void
		 */
		public function init()
		{
			error_reporting(-1);

			set_error_handler([$this, 'handleError']);

			set_exception_handler([$this, 'handleException']);

			register_shutdown_function([$this, 'handleShutdown']);

			if ('production' == APPLICATION_ENV) {
				ini_set('display_errors', 'Off');
			}
		}

		/**
		 * Convert a PHP error to an ErrorException.
		 *
		 * @param  int  $level
		 * @param  string  $message
		 * @param  string  $file
		 * @param  int  $line
		 * @param  array  $context
		 * @return void
		 */
		public function handleError($level, $message, $file = '', $line = 0, $context = [])
		{
			if (error_reporting() & $level) {
				throw new ErrorException($message, 0, $level, $file, $line);
			}
		}

		/**
		 * Handle an uncaught exception from the application.
		 *
		 * Note: Most exceptions can be handled via the try / catch block in
		 * the HTTP and Console kernels. But, fatal error exceptions must
		 * be handled differently since they are not normal exceptions.
		 *
		 * @param  \Exception  $e
		 * @return void
		 */
		public function handleException($e)
		{
			if (true === CLI) {
				$this->renderForConsole($e);
			} else {
				$this->renderHttpResponse($e);
			}
		}

		/**
		 * Render an exception to the console.
		 *
		 * @param  \Exception  $e
		 * @return void
		 */
		protected function renderForConsole($e)
		{
			dd($e);
		}

		/**
		 * Render an exception as an HTTP response and send it.
		 *
		 * @param  \Exception  $e
		 * @return void
		 */
		protected function renderHttpResponse($e)
		{
			dd($e);
		}

		/**
		 * Handle the PHP shutdown event.
		 *
		 * @return void
		 */
		public function handleShutdown()
		{
			if (!is_null($error = error_get_last())) {
				if (!$this->isFatal($error['type'])) {
					return;
					/* TODO */
				}

				$this->handleException(new FatalErrorException(
					$error['message'], $error['type'], 0, $error['file'], $error['line']
				));
			}
		}

		/**
		 * Determine if the error type is fatal.
		 *
		 * @param  int  $type
		 * @return bool
		 */
		protected function isFatal($type)
		{
			return Arrays::in($type, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE]);
		}
	}
