<?php
namespace ExtDirect;

use Nette\Reflection\AnnotationsParser;
use Psr\Http\Message\ResponseInterface;
use Zend\Diactoros\Response;
use Zend\Diactoros\Response\EmitterInterface;
use Zend\Diactoros\Response\SapiEmitter;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\FilesystemCache;


/**
 * Class Discoverer
 * @package ExtDirect
 */
class Discoverer
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
     * @param $path
     * @return array
     */
    public function loadDir($path)
    {
        $files = [];
        $globPath = $path . '/*.php';
        foreach (glob($globPath) as $filename) {
            $files[] = $filename;
        }

        return $files;
    }

    /**
     * @param \ReflectionClass $reflectedClass
     * @return array
     */
    public function getMethods(\ReflectionClass $reflectedClass)
    {
        $methods = [];

        foreach($reflectedClass->getMethods() as $reflectedMethod) {
            if (false === $reflectedMethod->isPublic()) {
                continue;
            }

            if ($reflectedMethod->isConstructor() || $reflectedMethod->isDestructor() || $reflectedMethod->isAbstract()) {
                continue;
            }

            $methodAnnotations = AnnotationsParser::getAll($reflectedMethod);
            if (false === isset($methodAnnotations['ExtDirect'])) {
                continue;
            }
            $method = [
                'name' => $reflectedMethod->getName(),
                'formHandler' => false
            ];

            if (!isset($methodAnnotations['ExtDirect\IgnoreParamsLength'])) {
                $method['len'] = $reflectedMethod->getNumberOfParameters();
            }

            if (isset($methodAnnotations['ExtDirect\FormHandler'])) {
                $method['formHandler'] = true;
            }

            if (isset($methodAnnotations['ExtDirect\ResultTransformer'])) {
                if (is_array($methodAnnotations['ExtDirect\ResultTransformer']) &&
                    is_string($methodAnnotations['ExtDirect\ResultTransformer'][0])) {
                    $method['resultTransformer'] = $methodAnnotations['ExtDirect\ResultTransformer'][0];
                }
            }

            $methods[$method['name']] = $method;
        }
        return $methods;
    }

    /**
     * Scan discoverable paths and get actions
     *
     * @return array
     */
    public function mapClasses()
    {
        $paths = $this->config->getDiscovererPaths();
        $files = $classMap = [];
        foreach ($paths as $path) {
            $files = array_merge($files, $this->loadDir($path));
        }

        foreach ($files as $file) {
            $fileContent = file_get_contents($file);
            $classes = array_keys(AnnotationsParser::parsePhp($fileContent));

            Config::includeFile($file);

            foreach ($classes as $className) {
                $class = new \ReflectionClass($className);
                if (!$class->isInstantiable()) {
                    continue;
                }

                $classAnnotations = AnnotationsParser::getAll($class);
                if (!isset($classAnnotations['ExtDirect'])) {
                    continue;
                }

                $methods = $this->getMethods($class);

                $classAlias = null;
                if (isset($classAnnotations['ExtDirect\Alias'])) {
                    if (is_array($classAnnotations['ExtDirect\Alias']) &&
                        is_string($classAnnotations['ExtDirect\Alias'][0])) {
                        $classAlias = $classAnnotations['ExtDirect\Alias'][0];
                    }
                }

                $actionName = $classAlias ?: $className;

                $classMap[$actionName]['action'] = $actionName;
                $classMap[$actionName]['class'] = $className;
                $classMap[$actionName]['file'] = $file;
                $classMap[$actionName]['methods'] = $methods;
            }
        }

        return $classMap;
    }

    /**
     * Build API declaration
     *
     * @param $classMap
     * @return array
     */
    public function buildApi($classMap)
    {
        $apiCfg = $this->config->getApi()['declaration'];

        $api = [
            'url' => $apiCfg['url'],
            'type' => $apiCfg['type']
        ];

        if (isset($apiCfg['id']) && !is_null($apiCfg['id'])) {
            $api['id'] = $apiCfg['id'];
        }
        if (isset($apiCfg['namespace']) && !is_null($apiCfg['namespace'])) {
            $api['namespace'] = $apiCfg['namespace'];
        }
        if (isset($apiCfg['timeout']) && !is_null($apiCfg['timeout'])) {
            $api['timeout'] = $apiCfg['timeout'];
        }

        foreach($classMap as $actionName => $actionProps) {
            array_walk($actionProps['methods'], function(&$method) {
                if (isset($method['resultTransformer'])) {
                    unset($method['resultTransformer']);
                }
            });

            $api['actions'][$actionName] = array_values($actionProps['methods']);
        }

        return $api;
    }

    /**
     * Start discovery process
     *
     * @param ResponseInterface|null $response
     * @param CacheProvider|null $cache
     * @return array
     */
    public function start(ResponseInterface $response = null,
                          CacheProvider $cache = null)
    {
        $cacheDir = $this->config->getCacheDirectory();
        $cacheKey = $this->config->getApiProperty('id');
        $cacheLifetime = $this->config->getCacheLifetime();

        $response = $response ?: new Response();
        $cache    = $cache ?: new FilesystemCache($cacheDir);

        if ($cache->contains($cacheKey)) {
            $classMap = $cache->fetch($cacheKey);
        } else {
            $classMap = $this->mapClasses();
            $cache->save($cacheKey, $classMap, $cacheLifetime);
        }

        $api = $this->buildApi($classMap);

        $body = sprintf('%s=%s;',
            $this->config->getApiDescriptor(),
            json_encode($api, \JSON_UNESCAPED_UNICODE));

        $response->getBody()->write($body);

        if (function_exists('openssl_random_pseudo_bytes')) {
            $token1 = bin2hex(openssl_random_pseudo_bytes(16));
            $token2 = bin2hex(openssl_random_pseudo_bytes(16));
        } else {
            $token1 = uniqid();
            $token2 = uniqid();
        }

        if (isset($_COOKIE['Ext-Direct-Token1'])) {
            $token1 = $_COOKIE['Ext-Direct-Token1'];
        } else {
            session_id($token1);
        }

        session_start();

        if (isset($_SESSION['Ext-Direct-Token2'])) {
            $token2 = $_SESSION['Ext-Direct-Token2'];
        }

        $_SESSION['Ext-Direct-Token2'] = $token2;
        setcookie('Ext-Direct-Token1', $token1, 0, '/', session_get_cookie_params()['domain']);

        $response->getBody()->write(sprintf('Ext.define(\'Ext.overrides.data.Connection\',{'.
            'override:\'Ext.data.Connection\',request:function(o){o=Ext.apply(o||{},{'.
            'withCredentials:true,cors:true,'.
            'headers:{\'Ext-Direct-Token1\':\'%s\',\'Ext-Direct-Token2\':\'%s\'}});'.
            'this.callParent([o]);}});', $token1, $token2));

        $this->response = $response->withHeader('Content-Type', 'text/javascript')
            ->withHeader('Set-Ext-Direct-Token1', $token1)
            ->withHeader('Set-Ext-Direct-Token2', $token2);
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