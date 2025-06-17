<?php
namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

class DataSanitizer {
    private $logger;

    public function __construct() {
        $this->logger = new Logger();
    }

    /**
     * Sanitiza un valor según su tipo
     *
     * @param mixed $value Valor a sanitizar
     * @param string $type Tipo de dato
     * @return mixed Valor sanitizado
     */
    public function sanitize($value, string $type = 'text') {
        if (is_array($value)) {
            return $this->sanitizeArray($value, $type);
        }

        switch ($type) {
            case 'text':
                return $this->sanitizeText($value);
            case 'email':
                return $this->sanitizeEmail($value);
            case 'url':
                return $this->sanitizeUrl($value);
            case 'int':
                return $this->sanitizeInt($value);
            case 'float':
                return $this->sanitizeFloat($value);
            case 'bool':
                return $this->sanitizeBool($value);
            case 'date':
                return $this->sanitizeDate($value);
            case 'time':
                return $this->sanitizeTime($value);
            case 'datetime':
                return $this->sanitizeDateTime($value);
            case 'phone':
                return $this->sanitizePhone($value);
            case 'postcode':
                return $this->sanitizePostcode($value);
            case 'sku':
                return $this->sanitizeSku($value);
            case 'price':
                return $this->sanitizePrice($value);
            case 'html':
                return $this->sanitizeHtml($value);
            case 'sql':
                return $this->sanitizeSql($value);
            case 'filename':
                return $this->sanitizeFilename($value);
            case 'path':
                return $this->sanitizePath($value);
            case 'json':
                return $this->sanitizeJson($value);
            case 'xml':
                return $this->sanitizeXml($value);
            default:
                return $this->sanitizeText($value);
        }
    }

    /**
     * Sanitiza un array de valores
     *
     * @param array $array Array a sanitizar
     * @param string $type Tipo de dato
     * @return array Array sanitizado
     */
    private function sanitizeArray(array $array, string $type): array {
        $result = [];
        foreach ($array as $key => $value) {
            $result[$this->sanitizeText($key)] = $this->sanitize($value, $type);
        }
        return $result;
    }

    /**
     * Sanitiza texto
     *
     * @param mixed $value Valor a sanitizar
     * @return string Texto sanitizado
     */
    private function sanitizeText($value): string {
        if (is_null($value)) {
            return '';
        }
        return sanitize_text_field((string)$value);
    }

    /**
     * Sanitiza email
     *
     * @param mixed $value Valor a sanitizar
     * @return string Email sanitizado
     */
    private function sanitizeEmail($value): string {
        if (is_null($value)) {
            return '';
        }
        return sanitize_email((string)$value);
    }

    /**
     * Sanitiza URL
     *
     * @param mixed $value Valor a sanitizar
     * @return string URL sanitizada
     */
    private function sanitizeUrl($value): string {
        if (is_null($value)) {
            return '';
        }
        return esc_url_raw((string)$value);
    }

    /**
     * Sanitiza entero
     *
     * @param mixed $value Valor a sanitizar
     * @return int Entero sanitizado
     */
    private function sanitizeInt($value): int {
        if (is_null($value)) {
            return 0;
        }
        return (int)$value;
    }

    /**
     * Sanitiza float
     *
     * @param mixed $value Valor a sanitizar
     * @return float Float sanitizado
     */
    private function sanitizeFloat($value): float {
        if (is_null($value)) {
            return 0.0;
        }
        return (float)$value;
    }

    /**
     * Sanitiza booleano
     *
     * @param mixed $value Valor a sanitizar
     * @return bool Booleano sanitizado
     */
    private function sanitizeBool($value): bool {
        if (is_null($value)) {
            return false;
        }
        return (bool)$value;
    }

    /**
     * Sanitiza fecha
     *
     * @param mixed $value Valor a sanitizar
     * @return string Fecha sanitizada
     */
    private function sanitizeDate($value): string {
        if (is_null($value)) {
            return '';
        }
        $date = strtotime((string)$value);
        return $date ? date('Y-m-d', $date) : '';
    }

    /**
     * Sanitiza hora
     *
     * @param mixed $value Valor a sanitizar
     * @return string Hora sanitizada
     */
    private function sanitizeTime($value): string {
        if (is_null($value)) {
            return '';
        }
        $time = strtotime((string)$value);
        return $time ? date('H:i:s', $time) : '';
    }

    /**
     * Sanitiza fecha y hora
     *
     * @param mixed $value Valor a sanitizar
     * @return string Fecha y hora sanitizada
     */
    private function sanitizeDateTime($value): string {
        if (is_null($value)) {
            return '';
        }
        $datetime = strtotime((string)$value);
        return $datetime ? date('Y-m-d H:i:s', $datetime) : '';
    }

    /**
     * Sanitiza teléfono
     *
     * @param mixed $value Valor a sanitizar
     * @return string Teléfono sanitizado
     */
    private function sanitizePhone($value): string {
        if (is_null($value)) {
            return '';
        }
        // Eliminar todo excepto números, +, -, espacios y paréntesis
        return preg_replace('/[^0-9+\-() ]/', '', (string)$value);
    }

    /**
     * Sanitiza código postal
     *
     * @param mixed $value Valor a sanitizar
     * @return string Código postal sanitizado
     */
    private function sanitizePostcode($value): string {
        if (is_null($value)) {
            return '';
        }
        // Eliminar todo excepto números y letras
        return preg_replace('/[^0-9A-Za-z]/', '', (string)$value);
    }

    /**
     * Sanitiza SKU
     *
     * @param mixed $value Valor a sanitizar
     * @return string SKU sanitizado
     */
    private function sanitizeSku($value): string {
        if (is_null($value)) {
            return '';
        }
        // Eliminar caracteres especiales, mantener alfanuméricos y guiones
        return preg_replace('/[^A-Za-z0-9\-]/', '', (string)$value);
    }

    /**
     * Sanitiza precio
     *
     * @param mixed $value Valor a sanitizar
     * @return float Precio sanitizado
     */
    private function sanitizePrice($value): float {
        if (is_null($value)) {
            return 0.0;
        }
        // Eliminar todo excepto números y punto decimal
        $price = preg_replace('/[^0-9.]/', '', (string)$value);
        return (float)$price;
    }

    /**
     * Sanitiza HTML
     *
     * @param mixed $value Valor a sanitizar
     * @return string HTML sanitizado
     */
    private function sanitizeHtml($value): string {
        if (is_null($value)) {
            return '';
        }
        return wp_kses_post((string)$value);
    }

    /**
     * Sanitiza SQL
     *
     * @param mixed $value Valor a sanitizar
     * @return string SQL sanitizado
     */
    private function sanitizeSql($value): string {
        if (is_null($value)) {
            return '';
        }
        global $wpdb;
        return $wpdb->prepare('%s', (string)$value);
    }

    /**
     * Sanitiza nombre de archivo
     *
     * @param mixed $value Valor a sanitizar
     * @return string Nombre de archivo sanitizado
     */
    private function sanitizeFilename($value): string {
        if (is_null($value)) {
            return '';
        }
        return sanitize_file_name((string)$value);
    }

    /**
     * Sanitiza ruta
     *
     * @param mixed $value Valor a sanitizar
     * @return string Ruta sanitizada
     */
    private function sanitizePath($value): string {
        if (is_null($value)) {
            return '';
        }
        return sanitize_file_name((string)$value);
    }

    /**
     * Sanitiza JSON
     *
     * @param mixed $value Valor a sanitizar
     * @return string JSON sanitizado
     */
    private function sanitizeJson($value): string {
        if (is_null($value)) {
            return '{}';
        }
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return wp_json_encode($value);
    }

    /**
     * Sanitiza XML
     *
     * @param mixed $value Valor a sanitizar
     * @return string XML sanitizado
     */
    private function sanitizeXml($value): string {
        if (is_null($value)) {
            return '';
        }
        // Eliminar caracteres no válidos para XML
        $value = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', (string)$value);
        return $value;
    }

    /**
     * Valida un valor según su tipo
     *
     * @param mixed $value Valor a validar
     * @param string $type Tipo de dato
     * @return bool True si es válido
     */
    public function validate($value, string $type = 'text'): bool {
        if (is_array($value)) {
            return $this->validateArray($value, $type);
        }

        switch ($type) {
            case 'text':
                return $this->validateText($value);
            case 'email':
                return $this->validateEmail($value);
            case 'url':
                return $this->validateUrl($value);
            case 'int':
                return $this->validateInt($value);
            case 'float':
                return $this->validateFloat($value);
            case 'bool':
                return $this->validateBool($value);
            case 'date':
                return $this->validateDate($value);
            case 'time':
                return $this->validateTime($value);
            case 'datetime':
                return $this->validateDateTime($value);
            case 'phone':
                return $this->validatePhone($value);
            case 'postcode':
                return $this->validatePostcode($value);
            case 'sku':
                return $this->validateSku($value);
            case 'price':
                return $this->validatePrice($value);
            case 'html':
                return $this->validateHtml($value);
            case 'sql':
                return $this->validateSql($value);
            case 'filename':
                return $this->validateFilename($value);
            case 'path':
                return $this->validatePath($value);
            case 'json':
                return $this->validateJson($value);
            case 'xml':
                return $this->validateXml($value);
            default:
                return $this->validateText($value);
        }
    }

    /**
     * Valida un array de valores
     *
     * @param array $array Array a validar
     * @param string $type Tipo de dato
     * @return bool True si es válido
     */
    private function validateArray(array $array, string $type): bool {
        foreach ($array as $value) {
            if (!$this->validate($value, $type)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Valida texto
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateText($value): bool {
        return is_string($value) || is_numeric($value);
    }

    /**
     * Valida email
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateEmail($value): bool {
        return is_email($value);
    }

    /**
     * Valida URL
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateUrl($value): bool {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Valida entero
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateInt($value): bool {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Valida float
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateFloat($value): bool {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }

    /**
     * Valida booleano
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateBool($value): bool {
        return is_bool($value) || in_array($value, [0, 1, '0', '1', true, false, 'true', 'false'], true);
    }

    /**
     * Valida fecha
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateDate($value): bool {
        $date = strtotime($value);
        return $date !== false && date('Y-m-d', $date) === $value;
    }

    /**
     * Valida hora
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateTime($value): bool {
        $time = strtotime($value);
        return $time !== false && date('H:i:s', $time) === $value;
    }

    /**
     * Valida fecha y hora
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateDateTime($value): bool {
        $datetime = strtotime($value);
        return $datetime !== false && date('Y-m-d H:i:s', $datetime) === $value;
    }

    /**
     * Valida teléfono
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validatePhone($value): bool {
        return preg_match('/^[0-9+\-() ]+$/', $value) === 1;
    }

    /**
     * Valida código postal
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validatePostcode($value): bool {
        return preg_match('/^[0-9A-Za-z]+$/', $value) === 1;
    }

    /**
     * Valida SKU
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateSku($value): bool {
        return preg_match('/^[A-Za-z0-9\-]+$/', $value) === 1;
    }

    /**
     * Valida precio
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validatePrice($value): bool {
        return is_numeric($value) && $value >= 0;
    }

    /**
     * Valida HTML
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateHtml($value): bool {
        return is_string($value);
    }

    /**
     * Valida SQL
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateSql($value): bool {
        return is_string($value);
    }

    /**
     * Valida nombre de archivo
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateFilename($value): bool {
        return is_string($value) && preg_match('/^[^\/\\:*?"<>|]+$/', $value) === 1;
    }

    /**
     * Valida ruta
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validatePath($value): bool {
        return is_string($value) && preg_match('/^[^:*?"<>|]+$/', $value) === 1;
    }

    /**
     * Valida JSON
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateJson($value): bool {
        if (is_string($value)) {
            json_decode($value);
            return json_last_error() === JSON_ERROR_NONE;
        }
        return false;
    }

    /**
     * Valida XML
     *
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    private function validateXml($value): bool {
        if (!is_string($value)) {
            return false;
        }
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($value);
        return $doc !== false;
    }
} 