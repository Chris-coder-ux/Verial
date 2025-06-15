<?php
/**
 * Clase de utilidades criptográficas para Mi Integración API
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 */

namespace MiIntegracionApi\Helpers;

/**
 * Clase de utilidades criptográficas
 * 
 * Contiene métodos para cifrar y descifrar credenciales de la API
 */
class Crypto {
    
    /**
     * Cifra un texto plano usando AES-256-CBC
     * 
     * @param string $plain_text Texto a cifrar
     * @param string $password Contraseña para cifrar (usa AUTH_KEY si no se proporciona)
     * @return string Texto cifrado en formato base64
     */
    public static function encrypt_api_secret($plain_text, $password = null) {
        $password       = $password ?: AUTH_KEY;
        $ivlen          = openssl_cipher_iv_length($cipher = 'AES-256-CBC');
        $iv             = openssl_random_pseudo_bytes($ivlen);
        $ciphertext_raw = openssl_encrypt($plain_text, $cipher, $password, OPENSSL_RAW_DATA, $iv);
        $hmac           = hash_hmac('sha256', $ciphertext_raw, $password, true);
        return base64_encode($iv . $hmac . $ciphertext_raw);
    }
    
    /**
     * Descifra un texto cifrado usando AES-256-CBC
     * 
     * @param string $ciphertext Texto cifrado en formato base64
     * @param string $password Contraseña para descifrar (usa AUTH_KEY si no se proporciona)
     * @return string|false Texto descifrado o false si falla la verificación de integridad
     */
    public static function decrypt_api_secret($ciphertext, $password = null) {
        $password           = $password ?: AUTH_KEY;
        $c                  = base64_decode($ciphertext);
        $ivlen              = openssl_cipher_iv_length($cipher = 'AES-256-CBC');
        $iv                 = substr($c, 0, $ivlen);
        $hmac               = substr($c, $ivlen, $sha2len = 32);
        $ciphertext_raw     = substr($c, $ivlen + $sha2len);
        $original_plaintext = openssl_decrypt($ciphertext_raw, $cipher, $password, OPENSSL_RAW_DATA, $iv);
        $calcmac            = hash_hmac('sha256', $ciphertext_raw, $password, true);
        if (!hash_equals($hmac, $calcmac)) {
            return false; // Integridad comprometida
        }
        return $original_plaintext;
    }
    
    /**
     * Función de compatibilidad para mantener código antiguo funcionando
     * 
     * @deprecated Usar Crypto::encrypt_api_secret() en su lugar
     * @internal Este método existe para compatibilidad con código existente
     */
    public static function mia_encrypt_api_secret($plain_text, $password = null) {
        return self::encrypt_api_secret($plain_text, $password);
    }
    
    /**
     * Función de compatibilidad para mantener código antiguo funcionando
     * 
     * @deprecated Usar Crypto::decrypt_api_secret() en su lugar
     * @internal Este método existe para compatibilidad con código existente
     */
    public static function mia_decrypt_api_secret($ciphertext, $password = null) {
        return self::decrypt_api_secret($ciphertext, $password);
    }
}

// Definimos las funciones globales en un archivo separado para evitar problemas de namespace
// Crear/modificar un archivo includesHelpers/compatibility.php si es necesario
