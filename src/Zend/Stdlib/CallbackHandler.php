<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Stdlib
 * @copyright  Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */

/**
 * CallbackHandler
 *
 * A handler for a event, event, filterchain, etc. Abstracts PHP callbacks,
 * primarily to allow for lazy-loading and ensuring availability of default
 * arguments (currying).
 *
 * @category   Zend
 * @package    Zend_Stdlib
 * @copyright  Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Zend_Stdlib_CallbackHandler
{
    /**
     * @var callable|WeakRef PHP callback to invoke
     */
    protected $callback;

    /**
     * Did an error occur when testing the validity of the callback?
     * @var bool
     */
    protected $error = false;

    /**
     * Callback metadata, if any
     * @var array
     */
    protected $metadata;

    /**
     * Constructor
     *
     * @param  callable $callback PHP callback
     * @param  array $metadata Options used by the callback handler (e.g., priority)
     * @return void
     */
    public function __construct($callback, array $metadata = array())
    {
        $this->metadata = $metadata;
        $this->registerCallback($callback);
    }

    /**
     * Error handler
     *
     * Used by registerCallback() when calling is_callable() to capture engine warnings.
     *
     * @param  int $errno
     * @param  string $errstr
     * @return void
     */
    public function errorHandler($errno, $errstr)
    {
        $this->error = true;
    }

    /**
     * Registers the callback provided in the constructor
     *
     * If you have pecl/weakref {@see http://pecl.php.net/weakref} installed,
     * this method provides additional behavior.
     *
     * If a callback is a functor, or an array callback composing an object
     * instance, this method will pass the object to a WeakRef instance prior
     * to registering the callback.
     *
     * @param  callable $callback
     * @return void
     */
    protected function registerCallback($callback)
    {
        set_error_handler(array($this, 'errorHandler'));
        $callable = is_callable($callback);
        restore_error_handler();
        if (!$callable || $this->error) {
            throw new Zend_Stdlib_Exception_InvalidCallbackException('Invalid callback provided; not callable');
        }

        // If pecl/weakref is not installed, simply store the callback and return
        set_error_handler(array($this, 'errorHandler'), E_WARNING);
        $callable = class_exists('WeakRef');
        restore_error_handler();
        if (!$callable || $this->error) {
            $this->callback = $callback;
            return;
        }

        // If WeakRef exists, we want to use it.

        // If we have a non-closure object, pass it to WeakRef, and then
        // register it.
        if (is_object($callback) && !$callback instanceof Closure) {
            $this->callback = new WeakRef($callback);
            return;
        }

        // If we have a string or closure, register as-is
        if (!is_array($callback)) {
            $this->callback = $callback;
            return;
        }

        list($target, $method) = $callback;

        // If we have an array callback, and the first argument is not an
        // object, register as-is
        if (!is_object($target)) {
            $this->callback = $callback;
            return;
        }

        // We have an array callback with an object as the first argument;
        // pass it to WeakRef, and then register the new callback
        $target         = new WeakRef($target);
        $this->callback = array($target, $method);
    }

    /**
     * Retrieve registered callback
     *
     * @return callable
     */
    public function getCallback()
    {
        $callback = $this->callback;

        // String callbacks -- simply return
        if (is_string($callback)) {
            return $callback;
        }

        // WeakRef callbacks -- pull it out of the object and return it
        if ($callback instanceof WeakRef) {
            return $callback->get();
        }

        // Non-WeakRef object callback -- return it
        if (is_object($callback)) {
            return $callback;
        }

        // Array callback with WeakRef object -- retrieve the object first, and
        // then return
        if (is_array($callback)) {
            list($target, $method) = $callback;
            if ($target instanceof WeakRef) {
                return array($target->get(), $method);
            }
        }

        // Otherwise, return it
        return $callback;
    }

    /**
     * Invoke handler
     *
     * @param  array $args Arguments to pass to callback
     * @return mixed
     */
    public function call(array $args = array())
    {
        $callback = $this->getCallback();

        if (is_string($callback)) {
            $this->validateStringCallbackFor54($callback);
        }

        // Minor performance tweak; use call_user_func() until > 3 arguments
        // reached
        switch (count($args)) {
            case 0:
                return $callback();
            case 1:
                return $callback(array_shift($args));
            case 2:
                $arg1 = array_shift($args);
                $arg2 = array_shift($args);
                return $callback($arg1, $arg2);
            case 3:
                $arg1 = array_shift($args);
                $arg2 = array_shift($args);
                $arg3 = array_shift($args);
                return $callback($arg1, $arg2, $arg3);
            default:
                return call_user_func_array($callback, $args);
        }
    }

    /**
     * Invoke as functor
     *
     * @return mixed
     */
    public function __invoke()
    {
        return $this->call(func_get_args());
    }

    /**
     * Get all callback metadata
     *
     * @return array
     */
    public function getMetadata()
    {
        return $this->metadata;
    }

    /**
     * Retrieve a single metadatum
     *
     * @param  string $name
     * @return mixed
     */
    public function getMetadatum($name)
    {
        if (array_key_exists($name, $this->metadata)) {
            return $this->metadata[$name];
        }
        return null;
    }

    /**
     * Validate a static method call
     *
     * Validates that a static method call in PHP 5.4 will actually work
     *
     * @param  string $callback
     * @return true
     * @throws Zend_Stdlib_Exception_InvalidCallbackException if invalid
     */
    protected function validateStringCallbackFor54($callback)
    {
        if (!strstr($callback, '::')) {
            return true;
        }

        list($class, $method) = explode('::', $callback, 2);

        if (!class_exists($class)) {
            throw new Zend_Stdlib_Exception_InvalidCallbackException(sprintf(
                'Static method call "%s" refers to a class that does not exist',
                $callback
            ));
        }

        $r = new ReflectionClass($class);
        if (!$r->hasMethod($method)) {
            throw new Zend_Stdlib_Exception_InvalidCallbackException(sprintf(
                'Static method call "%s" refers to a method that does not exist',
                $callback
            ));
        }
        $m = $r->getMethod($method);
        if (!$m->isStatic()) {
            throw new Zend_Stdlib_Exception_InvalidCallbackException(sprintf(
                'Static method call "%s" refers to a method that is not static',
                $callback
            ));
        }

        return true;
    }
}
