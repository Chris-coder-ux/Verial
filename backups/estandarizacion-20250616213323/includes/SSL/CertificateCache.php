<?php
/**
 * Clase para gestionar la caché de certificados SSL
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/SSL
 */

namespace MiIntegracionApi\SSL;

use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\CacheManager;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sistema de caché para certificados SSL
 *
 * Esta clase proporciona funcionalidades para almacenar en caché
 * certificados SSL y reducir las llamadas a disco/red.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/SSL
 */
class CertificateCache {
    /**
     * Instancia del logger
     *
     * @var Logger
     */
    private $logger;

    /**
     * Instancia del gestor de caché
     *
     * @var CacheManager
     */
    private $cache;

    /**
     * Tiempo de vida de la caché en segundos (por defecto 12 horas)
     *
     * @var int
     */
    private $cache_ttl = 43200;

    /**
     * Directorio de caché para certificados
     *
     * @var string
     */
    private $cache_dir;

    /**
     * Constructor
     *
     * @param Logger|null $logger Instancia del logger
     * @param CacheManager|null $cache_manager Instancia del gestor de caché
     */
    public function __construct($logger = null, $cache_manager = null) {
        $this->logger = $logger ?? new \MiIntegracionApi\Helpers\Logger('ssl_cache');
        $this->cache = $cache_manager ?? CacheManager::get_instance();
        $this->cache_dir = plugin_dir_path(dirname(__FILE__)) . '../certs/cache';
        
        // Asegurar que el directorio de caché exista
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }
    }

    /**
     * Establece el tiempo de vida de la caché
     *
     * @param int $ttl Tiempo en segundos
     * @return self
     */
    public function setCacheTTL(int $ttl): self {
        $this->cache_ttl = $ttl;
        return $this;
    }

    /**
     * Obtiene el contenido de un certificado, usando caché si está disponible
     *
     * @param string $cert_url URL o ruta del certificado
     * @param bool $force_refresh Forzar recarga ignorando caché
     * @return string|false Contenido del certificado o false si error
     */
    public function getCertificate(string $cert_url, bool $force_refresh = false) {
        $cache_key = 'cert_' . md5($cert_url);
        $file_cache_path = $this->cache_dir . '/' . $cache_key . '.pem';
        
        // Verificar caché en memoria primero (más rápida)
        if (!$force_refresh) {
            $cached_content = $this->cache->get($cache_key);
            if ($cached_content !== false) {
                $this->logger->debug("[SSL Cache] Certificado cargado desde memoria: $cert_url");
                return $cached_content;
            }
            
            // Verificar caché en disco
            if (file_exists($file_cache_path) && is_readable($file_cache_path)) {
                $file_age = time() - filemtime($file_cache_path);
                
                if ($file_age < $this->cache_ttl) {
                    $content = file_get_contents($file_cache_path);
                    if ($content !== false) {
                        // Actualizar caché en memoria
                        $this->cache->set($cache_key, $content, $this->cache_ttl);
                        $this->logger->debug("[SSL Cache] Certificado cargado desde disco: $cert_url");
                        return $content;
                    }
                }
            }
        }
        
        // Si no hay caché o se fuerza refresco, descargar/cargar el certificado
        $content = $this->loadCertificate($cert_url);
        
        if ($content) {
            // Guardar en caché de memoria
            $this->cache->set($cache_key, $content, $this->cache_ttl);
            
            // Guardar en caché de disco
            file_put_contents($file_cache_path, $content);
            
            $this->logger->debug("[SSL Cache] Certificado cargado y guardado en caché: $cert_url");
        }
        
        return $content;
    }
    
    /**
     * Carga un certificado desde una URL o ruta
     *
     * @param string $cert_url URL o ruta del certificado
     * @return string|false Contenido del certificado o false si error
     */
    private function loadCertificate(string $cert_url) {
        // Si es una ruta local
        if (file_exists($cert_url) && is_readable($cert_url)) {
            return file_get_contents($cert_url);
        }
        
        // Si es una URL
        if (filter_var($cert_url, FILTER_VALIDATE_URL)) {
            $response = wp_remote_get($cert_url, [
                'timeout' => 15,
                'sslverify' => true,
                'user-agent' => 'Mi-Integracion-API/' . (defined('MIAPI_VERSION') ? MIAPI_VERSION : '2.0.0'),
            ]);
            
            if (is_wp_error($response)) {
                $this->logger->error("[SSL Cache] Error descargando certificado: " . $response->get_error_message());
                return false;
            }
            
            if (wp_remote_retrieve_response_code($response) !== 200) {
                $this->logger->error("[SSL Cache] Error HTTP al descargar certificado");
                return false;
            }
            
            return wp_remote_retrieve_body($response);
        }
        
        $this->logger->error("[SSL Cache] URL/ruta de certificado inválida: $cert_url");
        return false;
    }
    
    /**
     * Limpia la caché de certificados
     *
     * @param string|null $cert_url URL específica o null para limpiar toda la caché
     * @return bool Éxito de la operación
     */
    public function clearCache(?string $cert_url = null): bool {
        if ($cert_url) {
            $cache_key = 'cert_' . md5($cert_url);
            $file_cache_path = $this->cache_dir . '/' . $cache_key . '.pem';
            
            // Limpiar caché en memoria
            $this->cache->delete($cache_key);
            
            // Limpiar caché en disco
            if (file_exists($file_cache_path)) {
                return unlink($file_cache_path);
            }
            
            return true;
        } else {
            // Limpiar toda la caché
            $this->cache->flush_group('ssl_certs');
            
            // Limpiar archivos de caché
            $files = glob($this->cache_dir . '/*.pem');
            $success = true;
            
            foreach ($files as $file) {
                if (!unlink($file)) {
                    $success = false;
                }
            }
            
            $this->logger->debug("[SSL Cache] Caché de certificados limpiada");
            return $success;
        }
    }
    
    /**
     * Obtener estadísticas de la caché
     *
     * @return array Estadísticas de la caché
     */
    public function getCacheStats(): array {
        $files = glob($this->cache_dir . '/*.pem');
        $total_size = 0;
        $oldest = time();
        $newest = 0;
        
        foreach ($files as $file) {
            $total_size += filesize($file);
            $mtime = filemtime($file);
            $oldest = min($oldest, $mtime);
            $newest = max($newest, $mtime);
        }
        
        return [
            'count' => count($files),
            'total_size' => $total_size,
            'oldest' => $oldest ? date('Y-m-d H:i:s', $oldest) : null,
            'newest' => $newest ? date('Y-m-d H:i:s', $newest) : null,
            'directory' => $this->cache_dir,
        ];
    }

    /**
     * Valida la integridad de la caché de certificados
     *
     * @return bool
     */
    public function validateCache(): bool {
        if (property_exists($this, 'logger') && $this->logger) {
            $this->logger->debug('[CertificateCache] validateCache() ejecutado');
        }
        // Aquí se podría agregar lógica de validación real en el futuro
        return true;
    }
}
