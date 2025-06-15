#!/bin/bash
# build-plugin-lightweight.sh
# Script para compilar una versión ligera del plugin Mi Integración API sin vendor completo

set -e

# Definición de variables
PLUGIN_SLUG="mi-integracion-api"
PLUGIN_DIR=$(pwd)
BUILD_DIR="/tmp/${PLUGIN_SLUG}-build"
ZIP_FILE="${PLUGIN_SLUG}-lightweight.zip"

# Verificar que estamos en el directorio correcto
if [ ! -f "${PLUGIN_DIR}/mi-integracion-api.php" ]; then
    echo "❌ Error: No se encuentra el archivo principal del plugin. Asegúrate de ejecutar este script desde el directorio raíz del plugin."
    exit 1
fi

echo "🔍 Iniciando compilación de versión ligera del plugin ${PLUGIN_SLUG}..."

# Verificar si ya existe un archivo ZIP y obtener su tamaño para comparación
if [ -f "$PLUGIN_DIR/$PLUGIN_SLUG.zip" ]; then
    ORIGINAL_SIZE=$(du -h "$PLUGIN_DIR/$PLUGIN_SLUG.zip" | cut -f1)
    echo "📊 Tamaño del plugin estándar: $ORIGINAL_SIZE"
fi

# Limpiar build anterior si existe
if [ -d "$BUILD_DIR" ]; then
    echo "🗑️  Eliminando directorio de build anterior..."
    rm -rf "$BUILD_DIR"
fi
mkdir -p "$BUILD_DIR"

echo "📂 Copiando archivos al directorio de build..."

# Copiar archivos y carpetas necesarios directamente al BUILD_DIR (sin crear subcarpeta)
cp index.php "$BUILD_DIR/"
cp mi-integracion-api.php "$BUILD_DIR/"
cp uninstall.php "$BUILD_DIR/"
cp -r admin "$BUILD_DIR/"
cp -r api_connector "$BUILD_DIR/"
cp -r assets "$BUILD_DIR/"
cp -r includes "$BUILD_DIR/"
cp -r languages "$BUILD_DIR/"
cp -r lib "$BUILD_DIR/"

# Copiar templates si existe
if [ -d "templates" ]; then
    cp -r templates "$BUILD_DIR/"
fi

# Copiar README.txt si existe (necesario para información del plugin en WordPress)
if [ -f "README.txt" ]; then
    cp README.txt "$BUILD_DIR/"
fi

# Eliminar archivos y carpetas innecesarios del build
echo "🧹 Limpiando archivos innecesarios..."

# Mantener docs y eliminar archivos no necesarios
if [ -d "$BUILD_DIR/docs" ]; then
    # Solo conservamos la documentación esencial para el usuario
    find "$BUILD_DIR/docs" -name '*.md' -not -name 'manual-usuario.md' -not -name 'guia-instalacion.md' -delete
fi

# Eliminar directorios y archivos de desarrollo
find "$BUILD_DIR" -name 'Legacy' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name '*.bak' -delete
find "$BUILD_DIR" -name '*.legacy' -delete
find "$BUILD_DIR" -name '*.sh' -delete
find "$BUILD_DIR" -name '*.log' -delete
find "$BUILD_DIR" -name '.DS_Store' -delete
find "$BUILD_DIR" -name '__MACOSX' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name 'tests' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name 'scripts' -type d -exec rm -rf {} \; 2>/dev/null || true

# No eliminar README.txt ya que es necesario para WordPress
find "$BUILD_DIR" -name '*.txt' -not -name 'README.txt' -delete

# Crear directorio para dependencias esenciales
echo "🔧 Creando autoloader ligero con solo clases esenciales..."
mkdir -p "$BUILD_DIR/vendor-min"

# Lista de dependencias esenciales (ajustar según las necesidades reales del plugin)
# Por ejemplo, solo incluir las clases de Blade que realmente se usan
if [ -d "vendor/eftec/bladeone" ]; then
    mkdir -p "$BUILD_DIR/vendor-min/eftec/bladeone"
    cp vendor/eftec/bladeone/BladeOne.php "$BUILD_DIR/vendor-min/eftec/bladeone/"
    # Copiar solo las clases esenciales de BladeOne
    if [ -d "vendor/eftec/bladeone/BladeOneCache" ]; then
        mkdir -p "$BUILD_DIR/vendor-min/eftec/bladeone/BladeOneCache"
        cp vendor/eftec/bladeone/BladeOneCache/BladeOneCache.php "$BUILD_DIR/vendor-min/eftec/bladeone/BladeOneCache/"
    fi
fi

# Para gettext
if [ -d "vendor/gettext/gettext" ]; then
    mkdir -p "$BUILD_DIR/vendor-min/gettext/gettext/src"
    cp -r vendor/gettext/gettext/src/Gettext.php "$BUILD_DIR/vendor-min/gettext/gettext/src/"
    # Añadir solo las clases esenciales
fi

# Para marc-mabe/php-enum
if [ -d "vendor/marc-mabe/php-enum/src" ]; then
    mkdir -p "$BUILD_DIR/vendor-min/marc-mabe/php-enum/src"
    cp -r vendor/marc-mabe/php-enum/src/Enum.php "$BUILD_DIR/vendor-min/marc-mabe/php-enum/src/"
fi

# Crear un autoloader personalizado ligero
cat > "$BUILD_DIR/vendor-min/autoload.php" << 'EOL'
<?php
/**
 * Autoloader ligero para Mi Integración API
 * Este autoloader reemplaza al de Composer para reducir el tamaño del plugin
 */

spl_autoload_register(function ($class) {
    // Mapa de namespaces a directorios
    $namespaces = [
        'MiIntegracionApi\\' => __DIR__ . '/../includes/',
        'eftec\\bladeone\\' => __DIR__ . '/eftec/bladeone/',
        'Gettext\\' => __DIR__ . '/gettext/gettext/src/',
        'MabeEnum\\' => __DIR__ . '/marc-mabe/php-enum/src/',
    ];
    
    // Buscar en cada namespace
    foreach ($namespaces as $prefix => $base_dir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        
        // Obtener la ruta relativa de la clase
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        
        if (file_exists($file)) {
            require $file;
            return true;
        }
    }
    
    return false;
});

// Cargar archivos de funciones
if (file_exists(__DIR__ . '/../includes/functions.php')) {
    require_once __DIR__ . '/../includes/functions.php';
}
EOL

# Modificar el archivo principal para usar nuestro autoloader ligero
sed -i 's/require_once plugin_dir_path( __FILE__ ) . "vendor\/autoload.php";/require_once plugin_dir_path( __FILE__ ) . "vendor-min\/autoload.php";/g' "$BUILD_DIR/mi-integracion-api.php"

# Verificar tamaño de las dependencias
VENDOR_MIN_SIZE=$(du -sh "$BUILD_DIR/vendor-min" | cut -f1)
echo "📊 Tamaño de dependencias optimizadas: $VENDOR_MIN_SIZE"

# Entrar al directorio build y crear el ZIP desde ahí para tener la estructura correcta
echo "🔒 Creando archivo ZIP ligero..."
cd "$BUILD_DIR"
zip -r "$ZIP_FILE" ./* > /dev/null

# Verificar si el ZIP se creó correctamente
if [ ! -f "$ZIP_FILE" ]; then
    echo "❌ Error: No se pudo crear el archivo ZIP. Verifica que tienes permisos y espacio suficiente."
    exit 1
fi

# Mover el ZIP al directorio original y al escritorio
echo "🚚 Moviendo el archivo ZIP ligero a las ubicaciones finales..."
cp "$ZIP_FILE" "$HOME/Escritorio/$ZIP_FILE"
mv "$ZIP_FILE" "$PLUGIN_DIR/$ZIP_FILE"

# Verificar que las copias se hicieron correctamente
if [ ! -f "$HOME/Escritorio/$ZIP_FILE" ] || [ ! -f "$PLUGIN_DIR/$ZIP_FILE" ]; then
    echo "⚠️ Advertencia: No se pudieron copiar los archivos a las ubicaciones finales."
fi

# Limpiar
cd - > /dev/null
rm -rf "$BUILD_DIR"

# Mostrar tamaño del archivo ZIP
LIGHT_SIZE=$(du -h "$PLUGIN_DIR/$ZIP_FILE" | cut -f1)

echo "✅ Plugin ligero compilado correctamente:"
echo "- 📁 $HOME/Escritorio/$ZIP_FILE"
echo "- 📁 $PLUGIN_DIR/$ZIP_FILE"
echo "- 📊 Tamaño del plugin ligero: $LIGHT_SIZE"
if [ ! -z "$ORIGINAL_SIZE" ]; then
    echo "- 📈 Comparación: Plugin estándar: $ORIGINAL_SIZE vs Plugin ligero: $LIGHT_SIZE"
fi
echo "- 📅 Fecha de compilación: $(date '+%Y-%m-%d %H:%M:%S')"
echo "⚠️ NOTA: Esta versión ligera podría requerir pruebas adicionales para verificar que todas las funcionalidades son compatibles con el autoloader personalizado."
