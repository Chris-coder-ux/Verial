<?php
/**
 * Definiciones de alias para mantener compatibilidad entre namespaces
 * 
 * Este archivo define alias de clases para mantener la compatibilidad
 * entre diferentes namespaces que pueden estar mezclados en el código.
 * 
 * @package MiIntegracionApi
 * @since 1.0.0
 */

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Crear alias de DataSanitizer para compatibilidad entre namespaces
if (!class_exists('MiIntegracionApi\Helpers\DataSanitizer') && class_exists('MiIntegracionApi\Core\DataSanitizer')) {
    class_alias('MiIntegracionApi\Core\DataSanitizer', 'MiIntegracionApi\Helpers\DataSanitizer');
}

// Otros alias pueden ser agregados aquí según sea necesario
