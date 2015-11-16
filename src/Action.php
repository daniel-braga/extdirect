<?php
namespace ExtDirect;

class Action implements ActionInterface
{
    protected $name;
    protected $class;
    protected $classFile;
    protected $method;
    protected $arguments = [];
    protected $tid;
    protected $formHandler;
    protected $files = [];
    protected $resultTransformer;

    /**
     * Action constructor.
     *
     * @param array $classMap
     * @param $method
     * @param $arguments
     * @param $tid
     * @param bool|false $formHandler
     * @param array $files
     */
    public function __construct(array $classMap, $method, $arguments, $tid, $formHandler = false, array $files = [])
    {
        $this->name = $classMap['action'];
        $this->class = $classMap['class'];
        $this->classFile = $classMap['file'];
        $this->method = $method;
        $this->arguments = (array) $arguments;
        $this->tid = $tid;
        $this->files = $files;
        $this->formHandler = $formHandler;

        if (isset($classMap['methods'][$method]['resultTransformer'])) {
            $this->resultTransformer = $classMap['methods'][$method]['resultTransformer'];
        }
    }

    /**
     * @return array
     */
    public function run()
    {
        $response = array(
            'action'  => $this->name,
            'method'  => $this->method,
            'result'  => null,
            'type'    => 'rpc',
            'tid'     => $this->tid
        );

        try {
            $result = $this->callAction();
            $response['result'] = $result;
        }
        catch (\Exception $e) {
            $response['type'] = 'exception';
            $response['message'] = $e->getMessage();
            $response['where'] = $e->getTraceAsString();
        }

        return $response;
    }

    public function isFormHandler()
    {
        return $this->formHandler;
    }

    public function isUpload()
    {
        return count($this->files) > 0;
    }

    public function hasFile($key)
    {
        return isset($this->files[$key]);
    }

    public function getFile($key)
    {
        if ($this->$this->hasFile($key)) {
            return $this->files[$key];
        }

        throw new \OutOfBoundsException(sprintf('File upload key `%s` is invalid', $key));
    }

    public function callAction()
    {
        Config::includeFile($this->classFile);

        $reflectedMethod = new \ReflectionMethod($this->class, $this->method);

        $arguments = [];
        if ($this->isFormHandler()) {
            /** @var \ReflectionParameter $reflectedParam */
            foreach ($reflectedMethod->getParameters() as $reflectedParam) {
                $paramName  = $reflectedParam->getName();
                $paramValue = null;

                if (isset($this->arguments[$paramName])) {
                    $paramValue = $this->arguments[$paramName];
                } elseif ($this->isUpload() && $this->hasFile($paramName)) {
                    $paramValue = $this->getFile($paramName);
                } elseif ($reflectedParam->isDefaultValueAvailable()) {
                    $paramValue = $reflectedParam->getDefaultValue();
                }

                $arguments[] = $paramValue;
            }
        } else {
            $arguments = $this->arguments;
        }

        $result = call_user_func_array(array($this->class, $this->method), $arguments);

        if (is_callable($this->resultTransformer)) {
            $result = call_user_func($this->resultTransformer, $this, $result);
        }

        return $result;
    }
}