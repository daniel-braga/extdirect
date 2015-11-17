<?php
namespace ExtDirect;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Zend\Diactoros\Response\EmitterInterface;
use Zend\Diactoros\ServerRequestFactory;
use Zend\Diactoros\Response;
use Zend\Diactoros\Response\SapiEmitter;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\FilesystemCache;


/**
 * Class Discoverer
 * @package ExtDirect
 */
class Router
{
    /**
     * @var \ExtDirect\Config
     */
    protected $config;

    /**
     * @var ResponseInterface
     */
    protected $response;

    /**
     * Discoverer constructor.
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $paths = $config->getDiscovererPaths();
        if (count($paths) == 0) {
            throw new \DomainException('The Config object has no discoverable paths');
        }

        //@TODO check mandatory properties of API declaration (url, type)

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                throw new \InvalidArgumentException(sprintf('%s is not a directory', $path));
            }

            if (!is_readable($path)) {
                throw new \DomainException(sprintf('%s is not a readable directory', $path));
            }
        }

        $this->config = $config;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param ServerRequestInterface $request
     * @return bool
     */
    public function isFormRequest(ServerRequestInterface $request)
    {
        $contentTypes = $request->getHeader('Content-Type');
        foreach($contentTypes as $contentType) {
            if (false !== strpos($contentType, 'application/x-www-form-urlencoded')) {
                return true;
            }
            if (false !== strpos($contentType, 'multipart/form-data')) {
                return true;
            }
        }
        return false;
    }

    public function isUpload(ServerRequestInterface $request)
    {
        $contentTypes = $request->getHeader('Content-Type');
        foreach($contentTypes as $contentType) {
            if (false !== strpos($contentType, 'multipart/form-data')) {
                return (count($request->getUploadedFiles()) > 0);
            }
        }
        return false;
    }

    /**
     * @param $actionMap
     * @param $method
     * @return bool
     */
    public function methodIsAllowed($actionMap, $method)
    {
        $allowed = isset($actionMap['methods'][$method]);

        return $allowed;
    }

    /**
     *
     * @param ServerRequestInterface $request
     * @param array $classMap
     * @return Action[]
     */
    public function getActions(ServerRequestInterface $request, array $classMap)
    {
        $actions = [];

        if ($this->isFormRequest($request)) {
            $call = $request->getParsedBody();

            $actionName = $call['extAction'];
            if (!isset($classMap[$actionName])) {
                throw new \InvalidArgumentException(sprintf('Unknow action %s', $actionName));
            }
            $actionMap = $classMap[$actionName];

            if (!$this->methodIsAllowed($actionMap, $call['extMethod'])) {
                throw new \InvalidArgumentException(sprintf('Method %s is not allowed', $call['extMethod']));
            }

            $postVars = $call;
            foreach (['extAction', 'extMethod', 'extTID', 'extUpload', 'extType'] as $extVar) {
                if (isset($postVars[$extVar])) {
                    unset($postVars[$extVar]);
                }
            }

            $actions[] = new Action($this->config, $actionMap, $call['extMethod'], $postVars, $call['extTID'], true, $request->getUploadedFiles());

            return $actions;
        }
        $calls = json_decode($request->getBody()->getContents());

        if (!is_array($calls)) {
            $calls = array($calls);
        }

        foreach($calls as $call) {
            if (isset($call->type) && $call->type == 'rpc') {
                $actionName = $call->action;
                if (!isset($classMap[$actionName])) {
                    throw new \InvalidArgumentException(sprintf('Unknow action %s', $actionName));
                }
                $actionMap = $classMap[$actionName];

                if (!$this->methodIsAllowed($actionMap, $call->method)) {
                    throw new \InvalidArgumentException(sprintf('Method %s is not allowed', $call->method));
                }

                $actions[] = new Action($this->config, $actionMap, $call->method, $call->data, $call->tid);
            }
        }

        return $actions;
    }

    /**
     *
     * @param ServerRequestInterface|null $request
     * @param ResponseInterface|null $response
     * @param CacheProvider|null $cache
     * @return array
     */
    public function route(ServerRequestInterface $request = null,
                          ResponseInterface $response = null,
                          CacheProvider $cache = null)
    {
        $cacheDir = $this->config->getCacheDirectory();
        $cacheKey = $this->config->getApiProperty('id');
        $cacheLifetime = $this->config->getCacheLifetime();

        $request  = $request ?: ServerRequestFactory::fromGlobals();
        $response = $response ?: new Response();
        $cache    = $cache ?: new FilesystemCache($cacheDir);

        if ($cache->contains($cacheKey)) {
            $classMap = $cache->fetch($cacheKey);

        } else {
            $discoverer = new Discoverer($this->config);
            $classMap = $discoverer->mapClasses();

            $cache->save($cacheKey, $classMap, $cacheLifetime);
        }

        $actionsResults = [];
        $actions = $this->getActions($request, $classMap);
        $upload = false;
        foreach ($actions as $action) {
            $actionsResults[] = $action->run();
            if ($action->isUpload()) $upload = true;
        }

        if ($upload) {
            $result = sprintf('<html><body><textarea>%s</textarea></body></html>',
                preg_replace('/&quot;/', '\\&quot;', json_encode($actionsResults[0], \JSON_UNESCAPED_UNICODE)));

            $response->getBody()->write($result);
            $this->response = $response->withHeader('Content-Type', 'text/html');
        } else {
            if (count($actionsResults) == 1) {
                $response->getBody()->write(json_encode($actionsResults[0], \JSON_UNESCAPED_UNICODE));
            } else {
                $response->getBody()->write(json_encode($actionsResults, \JSON_UNESCAPED_UNICODE));
            }
            $this->response = $response->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * @param EmitterInterface|null $emitter
     */
    public function output(EmitterInterface $emitter = null)
    {
        $emitter = $emitter ?: new SapiEmitter();
        $emitter->emit($this->getResponse());
    }
}