#!/bin/bash
# build-plugin.sh
# Script para compilar el plugin Mi Integración API en un archivo ZIP listo para instalar en WordPress

set -e

PLUGIN_SLUG="mi-integracion-api"
BUILD_DIR="/tmp/${PLUGIN_SLUG}"
ZIP_FILE="${PLUGIN_SLUG}.zip"

# Limpiar build anterior si existe
test -d "$BUILD_DIR" && rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

# Copiar archivos y carpetas necesarios
cp index.php "$BUILD_DIR/"
cp mi-integracion-api.php "$BUILD_DIR/"
cp uninstall.php "$BUILD_DIR/"
cp composer.json "$BUILD_DIR/" || true
cp composer.lock "$BUILD_DIR/" || true
cp qodana.yaml "$BUILD_DIR/" || true

# Copiar directorios principales
cp -r admin "$BUILD_DIR/"
cp -r api_connector "$BUILD_DIR/"
cp -r assets "$BUILD_DIR/"
cp -r certs "$BUILD_DIR/"
cp -r docs "$BUILD_DIR/"
cp -r includes "$BUILD_DIR/"
cp -r languages "$BUILD_DIR/"
cp -r lib "$BUILD_DIR/"
cp -r templates "$BUILD_DIR/" 2>/dev/null || true

# Instalar dependencias de producción con composer si existe composer.json
if [ -f "$BUILD_DIR/composer.json" ]; then
    echo "📦 Instalando dependencias de producción (composer install --no-dev --optimize-autoloader)..."
    (cd "$BUILD_DIR" && composer install --no-dev --optimize-autoloader --quiet)
    if [ ! -f "$BUILD_DIR/vendor/autoload.php" ]; then
        echo "❌ Error: No se generó vendor/autoload.php. El build se aborta para evitar un plugin defectuoso."
        exit 1
    fi
    echo "✅ vendor/autoload.php generado correctamente."
fi

# Verificar que los archivos críticos existan
CRITICAL_FILES=(
    "$BUILD_DIR/includes/Core/TransactionManager.php"
    "$BUILD_DIR/includes/Core/SyncMetrics.php"
    "$BUILD_DIR/includes/Core/ConfigManager.php"
    "$BUILD_DIR/includes/Sync/SyncProductos.php"
    "$BUILD_DIR/includes/Sync/SyncPedidos.php"
    "$BUILD_DIR/includes/Sync/SyncClientes.php"
    "$BUILD_DIR/includes/Core/ApiConnector.php"
    "$BUILD_DIR/includes/Core/Sync_Manager.php"
    "$BUILD_DIR/includes/Core/LogManager.php"
    "$BUILD_DIR/includes/Core/MemoryManager.php"
    "$BUILD_DIR/includes/Core/RetryManager.php"
    "$BUILD_DIR/includes/Core/SyncRecovery.php"
)

for file in "${CRITICAL_FILES[@]}"; do
    if [ ! -f "$file" ]; then
        echo "❌ Error: Archivo crítico no encontrado: $file"
        exit 1
    fi
done

# Eliminar archivos y carpetas innecesarios del build
find "$BUILD_DIR" -name 'Legacy' -type d -exec rm -rf {} +
find "$BUILD_DIR" -name '*.bak' -delete
find "$BUILD_DIR" -name '*.legacy' -delete
find "$BUILD_DIR" -name '*.md' -delete
find "$BUILD_DIR" -name '*.sh' -delete
find "$BUILD_DIR" -name '*.log' -delete
find "$BUILD_DIR" -name '.DS_Store' -delete
find "$BUILD_DIR" -name '__MACOSX' -exec rm -rf {} +
find "$BUILD_DIR" -name 'tests' -type d -exec rm -rf {} +
find "$BUILD_DIR" -name 'scripts' -type d -exec rm -rf {} +
find "$BUILD_DIR" -name 'Examples' -type d -exec rm -rf {} +
find "$BUILD_DIR" -name 'Patches' -type d -exec rm -rf {} +
find "$BUILD_DIR" -name 'Polyfills' -type d -exec rm -rf {} +

# Verificar permisos de archivos
find "$BUILD_DIR" -type f -exec chmod 644 {} \;
find "$BUILD_DIR" -type d -exec chmod 755 {} \;

# Crear el archivo ZIP
cd "/tmp"
zip -r "$ZIP_FILE" "$PLUGIN_SLUG"

# Mover el ZIP al directorio original (raíz del plugin)
mv "$ZIP_FILE" "$OLDPWD/"

# Copiar el ZIP también al Escritorio del usuario
cp "$OLDPWD/$ZIP_FILE" "$HOME/Escritorio/$ZIP_FILE"

# Limpiar
rm -rf "$BUILD_DIR"

echo "✅ Plugin compilado correctamente: $ZIP_FILE (en la raíz del plugin y en el Escritorio)"
echo "📦 Archivos críticos verificados:"
for file in "${CRITICAL_FILES[@]}"; do
    echo "   ✓ $(basename "$file")"
done
