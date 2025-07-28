<?php

namespace App\Logging;

use Monolog\Logger;

class SolarWindsLogger
{
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('solarwinds');
        
        $handler = new SolarWindsHttpHandler(
            env('SOLARWINDS_HTTP_ENDPOINT'),
            env('SOLARWINDS_API_TOKEN'),
            $config['level'] ?? 'info'
        );
        
        $logger->pushHandler($handler);
        return $logger;
    }
}