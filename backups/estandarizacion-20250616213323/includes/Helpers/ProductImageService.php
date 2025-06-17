<?php
namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Corregido: usar el namespace real en minúscula
use MiIntegracionApi\helpers\Logger;

// declare(strict_types=1); // <-- opcional

class ProductImageService {
	/**
	 * Descarga y adjunta imágenes a un producto de WooCommerce.
	 *
	 * @param int                $product_id
	 * @param array<int, string> $image_urls
	 * @param string             $product_name
	 * @return array<int, int> IDs de adjuntos creados
	 */
	public function download_and_attach_images( int $product_id, array $image_urls, string $product_name ): array {
		// Validar ABSPATH
		$abs_path = defined( 'ABSPATH' ) && is_string( ABSPATH ) ? ABSPATH : '';
		if ( ! function_exists( 'download_url' ) && $abs_path !== '' ) {
			require_once $abs_path . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) && $abs_path !== '' ) {
			require_once $abs_path . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) && $abs_path !== '' ) {
			require_once $abs_path . 'wp-admin/includes/media.php';
		}

		$attached_image_ids = array();
		$is_first_image     = true;

		if ( empty( $product_id ) || get_post_type( $product_id ) !== 'product' || get_post_status( $product_id ) === false ) {
			if ( class_exists( '\\MiIntegracionApi\\Helpers\\Logger' ) ) {
				Logger::error( '[ProductImageService] ID de producto inválido o no es un producto: ' . (string) $product_id, array( 'context' => 'mia-image-service' ) );
			}
			return array();
		}

		foreach ( $image_urls as $url ) {
			if ( ! is_string( $url ) || empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				if ( class_exists( '\\MiIntegracionApi\\Helpers\\Logger' ) ) {
					Logger::error( '[ProductImageService] URL de imagen inválida omitida: ' . ( is_string( $url ) ? $url : '' ), array( 'context' => 'mia-image-service' ) );
				}
				continue;
			}

			// $url es string garantizado aquí por el tipado de la función
			$file_name_base = sanitize_title( $product_name ) . '-' . md5( basename( (string) $url ) );
			$path           = parse_url( (string) $url, PHP_URL_PATH );
			$file_extension = is_string( $path ) ? pathinfo( $path, PATHINFO_EXTENSION ) : null;
			if ( ! is_string( $file_extension ) || $file_extension === '' ) {
				$file_extension = 'jpg';
			}

			$allowed_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
			if ( ! in_array( strtolower( (string) $file_extension ), $allowed_extensions, true ) ) {
				if ( class_exists( '\\MiIntegracionApi\\Helpers\\Logger' ) ) {
					Logger::error( '[ProductImageService] Extensión de imagen no soportada (' . (string) $file_extension . ') en: ' . (string) $url, array( 'context' => 'mia-image-service' ) );
				}
				continue;
			}

			$file_name      = $file_name_base . '.' . (string) $file_extension;
			$temp_file_path = download_url( (string) $url );

			if ( is_wp_error( $temp_file_path ) ) {
				if ( class_exists( '\\MiIntegracionApi\\Helpers\\Logger' ) ) {
					$msg = '[ProductImageService] Error al descargar imagen desde ' . (string) $url . ': ' . ( $temp_file_path instanceof \WP_Error ? $temp_file_path->get_error_message() : '' );
					Logger::error( $msg, array( 'context' => 'mia-image-service' ) );
				}
				continue;
			}
			if ( ! is_string( $temp_file_path ) ) {
				continue;
			}

			$file_array = array(
				'name'     => $file_name,
				'tmp_name' => $temp_file_path,
			);

			$image_description = 'Imagen para ' . $product_name;

			$attachment_id = media_handle_sideload( $file_array, $product_id, $image_description );

			if ( is_wp_error( $attachment_id ) ) {
				if ( class_exists( '\\MiIntegracionApi\\Helpers\\Logger' ) ) {
					$msg = '[ProductImageService] Error al añadir imagen a la biblioteca de medios desde ' . (string) $url . ': ' . ( $attachment_id instanceof \WP_Error ? $attachment_id->get_error_message() : '' );
					Logger::error( $msg, array( 'context' => 'mia-image-service' ) );
				}
				if ( file_exists( $temp_file_path ) ) {
					@unlink( $temp_file_path );
				}
				continue;
			}
			if ( file_exists( $temp_file_path ) ) {
				@unlink( $temp_file_path );
			}

			if ( is_int( $attachment_id ) ) {
				$attached_image_ids[] = $attachment_id;
				if ( $is_first_image ) {
					set_post_thumbnail( $product_id, $attachment_id );
					$is_first_image = false;
				}
			}
		}

		// Galería: acumular si ya existía
		if ( count( $attached_image_ids ) > 1 ) {
			$gallery_ids      = array_values( array_diff( $attached_image_ids, array( get_post_thumbnail_id( $product_id ) ) ) );
			$existing_gallery = get_post_meta( $product_id, '_product_image_gallery', true );
			if ( is_string( $existing_gallery ) && ! empty( $existing_gallery ) ) {
				$existing_gallery_ids = explode( ',', $existing_gallery );
				$gallery_ids          = array_unique( array_merge( $existing_gallery_ids, $gallery_ids ) );
			}
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
		} elseif ( empty( $attached_image_ids ) && has_post_thumbnail( $product_id ) ) {
			delete_post_meta( $product_id, '_product_image_gallery' );
		}

		return $attached_image_ids;
	}
}
