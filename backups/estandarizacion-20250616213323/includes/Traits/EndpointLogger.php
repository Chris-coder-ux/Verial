<?php
namespace MiIntegracionApi\Traits;

use MiIntegracionApi\Helpers\Logger;

trait EndpointLogger {

	/**
	 * Instancia del logger de dominio.
	 *
	 * @var \MiIntegracionApi\Helpers\Logger
	 */
	protected Logger $logger;

	/**
	 * Inicializa el logger adecuado según el dominio.
	 *
	 * @param string $dominio ('pedidos', 'productos', 'sync', 'clientes', etc.)
	 * @return void
	 */
	protected function init_logger( string $dominio = 'base' ): void {
		$this->logger = new Logger();
		$this->logger->set_category($dominio); // Establecer la categoría para esta instancia
	}

	/**
	 * Registra un mensaje informativo en el log.
	 *
	 * @param string $message Mensaje a registrar
	 * @param array  $context Contexto del mensaje
	 * @return void
	 */
	protected function log_info( string $message, array $context = [] ): void {
		if ( $this->logger ) {
			$this->logger->info( $message, $context, $this->logger->get_category() );
		}
	}

	/**
	 * Registra un error en el log.
	 *
	 * @param string $message Mensaje a registrar
	 * @param array  $context Contexto del mensaje
	 * @return void
	 */
	protected function log_error( string $message, array $context = [] ): void {
		if ( $this->logger ) {
			$this->logger->error( $message, $context, $this->logger->get_category() );
		}
	}

	/**
	 * Registra una advertencia en el log.
	 *
	 * @param string $message Mensaje a registrar
	 * @param array  $context Contexto del mensaje
	 * @return void
	 */
	protected function log_warning( string $message, array $context = [] ): void {
		if ( $this->logger ) {
			$this->logger->warning( $message, $context, $this->logger->get_category() );
		}
	}
	
	/**
	 * Registra un mensaje de depuración en el log.
	 *
	 * @param string $message Mensaje a registrar
	 * @param array  $context Contexto del mensaje
	 * @return void
	 */
	protected function log_debug( string $message, array $context = [] ): void {
		if ( $this->logger ) {
			$this->logger->debug( $message, $context, $this->logger->get_category() );
		}
	}
}
