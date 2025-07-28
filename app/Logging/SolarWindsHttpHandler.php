<?php

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Formatter\LineFormatter;

class SolarWindsHttpHandler extends AbstractProcessingHandler
{
    protected string $endpoint;
    protected string $token;

    public function __construct(string $endpoint, string $token, $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->endpoint = $endpoint;
        $this->token = $token;
    }

    protected function write(LogRecord $record): void
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $record->formatted,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Authorization: Bearer ' . $this->token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($response === false || $httpCode >= 400) {
            error_log('SolarWinds logging failed: HTTP ' . $httpCode);
        }
        
        curl_close($ch);
    }

    protected function getDefaultFormatter(): \Monolog\Formatter\FormatterInterface
    {
        return new LineFormatter("[%datetime%] %channel%.%level_name%: %message% %context%\n");
    }
}