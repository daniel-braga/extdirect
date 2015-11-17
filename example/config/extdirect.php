<?php
return [
    'discoverer' => [
        'paths' => [
            __DIR__ . '/../src',
        ],
        'resultTransformer' => function(\ExtDirect\ActionInterface $action, $result, \Exception $ex = null) {
            if (is_null($ex)) {
                return [
                    'success' => true,
                    'data' => $result
                ];
            } else {
                return [
                    'success' => false,
                    'data' => $ex->getMessage()
                ];
            }
        }
    ],
    'cache' => [
        'directory' => __DIR__ . '/../cache',
        'lifetime' => 60,
    ],
    'api' => [
        'descriptor' => 'window.uERP_REMOTING_API',
        'declaration' => [
            'url' => 'http://localhost:8081/router.php',
            'type' => 'remoting',
            'id' => 'uERP', // it's required for the cache mechanism
            'namespace' => 'Ext.php',
            'timeout' => null,
        ]
    ]
];