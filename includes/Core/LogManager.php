<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Gestor de logging para sincronización
 */
class LogManager
{
    private const LOG_LEVELS = [
        'emergency' => 0,
        'alert'     => 1,
        'critical'  => 2,
        'error'     => 3,
        'warning'   => 4,
        'notice'    => 5,
        'info'      => 6,
        'debug'     => 7
    ];

    private Logger $logger;
    private string $context;
    private array $defaultContext = [];
    private int $minLevel;

    /**
     * Obtiene la instancia subyacente del Logger
     * 
     * @return Logger La instancia interna de Logger
     */
    public function get_logger_instance(): Logger
    {
        return $this->logger;
    }

    public function __construct(string $context, array $defaultContext = [], string $minLevel = 'info')
    {
        $this->logger = new Logger($context);
        $this->context = $context;
        $this->defaultContext = $defaultContext;
        $this->minLevel = self::LOG_LEVELS[$minLevel] ?? self::LOG_LEVELS['info'];
    }

    /**
     * Registra un mensaje de emergencia
     * 
     * @param string $message Mensaje a registrar
     * @param array<string, mixed> $context Contexto adicional
     * @return void
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * Registra un mensaje de alerta
     * 
     * @param string $message Mensaje a registrar
     * @param array<string, mixed> $context Contexto adicional
     * @return void
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * Registra un mensaje crítico
     * 
     * @param string $message Mensaje a registrar
     * @param array<string, mixed> $context Contexto adicional
     * @return void
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * Registra un mensaje de error
     * 
     * @param string $message Mensaje a registrar
     * @param array<string, mixed> $context Contexto adicional
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Registra un mensaje de advertencia
     * 
     * @param string $message Mensaje a registrar
     * @param array<string, mixed> $context Contexto adicional
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Registra un mensaje de notificación
     * 
     * @param string $message Mensaje a registrar
     * @param array<string, mixed> $context Contexto adicional
     * @return void
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * Registra un mensaje informativo
     * 
     * @param string $message Mensaje a registrar
     * @param array<string, mixed> $context Contexto adicional
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Registra un mensaje de depuración
     * 
     * @param string $message Mensaje a registrar
     * @param array<string, mixed> $context Contexto adicional
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Registra un mensaje con el nivel especificado
     * 
     * @param string $level Nivel de log
     * @param string $message Mensaje a registrar
     * @param array<string, mixed> $context Contexto adicional
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (self::LOG_LEVELS[$level] > $this->minLevel) {
            return;
        }

        $context = array_merge($this->defaultContext, $context);
        $context['timestamp'] = date('Y-m-d H:i:s');
        $context['level'] = strtoupper($level);
        $context['context'] = $this->context;
        
        // Convertir el nivel de texto a la constante correspondiente en Logger
        $loggerLevel = $this->mapLogLevel($level);
        
        $this->logger->log($loggerLevel, $this->formatMessage($message, $context), $context);
    }
    
    /**
     * Mapea un nivel de log textual a la constante correspondiente en Logger
     *
     * @param string $level Nivel de log textual
     * @return string Constante de nivel equivalente en Logger
     */
    private function mapLogLevel(string $level): string
    {
        $levelMap = [
            'debug'     => Logger::LEVEL_DEBUG,
            'info'      => Logger::LEVEL_INFO,
            'notice'    => Logger::LEVEL_NOTICE,
            'warning'   => Logger::LEVEL_WARNING,
            'error'     => Logger::LEVEL_ERROR,
            'critical'  => Logger::LEVEL_CRITICAL,
            'alert'     => Logger::LEVEL_ALERT,
            'emergency' => Logger::LEVEL_EMERGENCY
        ];
        
        return $levelMap[$level] ?? Logger::LEVEL_INFO;
    }

    /**
     * Formatea el mensaje de log
     * 
     * @param string $message Mensaje base
     * @param array<string, mixed> $context Contexto
     * @return string Mensaje formateado
     */
    private function formatMessage(string $message, array $context): string
    {
        $formattedMessage = sprintf(
            '[%s] [%s] [%s] %s',
            $context['timestamp'],
            $context['level'],
            $context['context'],
            $message
        );

        if (!empty($context['entity'])) {
            $formattedMessage .= sprintf(' [Entity: %s]', $context['entity']);
        }

        if (!empty($context['operation_id'])) {
            $formattedMessage .= sprintf(' [Operation: %s]', $context['operation_id']);
        }

        if (!empty($context['error'])) {
            $formattedMessage .= sprintf(' [Error: %s]', $context['error']);
        }

        if (!empty($context['code'])) {
            $formattedMessage .= sprintf(' [Code: %s]', $context['code']);
        }

        return $formattedMessage;
    }

    /**
     * Establece el contexto por defecto
     * 
     * @param array<string, mixed> $context Contexto por defecto
     * @return void
     */
    public function setDefaultContext(array $context): void
    {
        $this->defaultContext = $context;
    }

    /**
     * Establece el nivel mínimo de log
     * 
     * @param string $level Nivel mínimo
     * @return void
     */
    public function setMinLevel(string $level): void
    {
        if (isset(self::LOG_LEVELS[$level])) {
            $this->minLevel = self::LOG_LEVELS[$level];
        }
    }

    /**
     * Obtiene el contexto actual
     * 
     * @return string Contexto
     */
    public function getContext(): string
    {
        return $this->context;
    }

    /**
     * Obtiene el nivel mínimo actual
     * 
     * @return string Nivel mínimo
     */
    public function getMinLevel(): string
    {
        return array_search($this->minLevel, self::LOG_LEVELS, true) ?: 'info';
    }
} 