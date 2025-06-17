<?php
/**
 * Interfaz de Logger compatible con PSR-3
 * 
 * Esta es una implementación simplificada para evitar dependencias externas
 * pero manteniendo compatibilidad con PSR-3 LoggerInterface
 *
 * @package MiIntegracionApi\Helpers
 */

namespace MiIntegracionApi\Helpers;

interface ILogger {
    /**
     * Sistema para registrar mensajes de emergencia.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function emergency($message, array $context = array());

    /**
     * Sistema para registrar alertas.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function alert($message, array $context = array());

    /**
     * Sistema para registrar errores críticos.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function critical($message, array $context = array());

    /**
     * Sistema para registrar errores.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function error($message, array $context = array());

    /**
     * Sistema para registrar advertencias.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function warning($message, array $context = array());

    /**
     * Sistema para registrar avisos.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function notice($message, array $context = array());

    /**
     * Sistema para registrar información.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function info($message, array $context = array());

    /**
     * Sistema para registrar mensajes de depuración.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function debug($message, array $context = array());

    /**
     * Sistema para registrar mensajes con un nivel arbitrario.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = array());
}
