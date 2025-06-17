#!/bin/bash
# build-plugin-fixed.sh
# Script corregido para compilar el plugin Mi Integración API en un archivo ZIP con la estructura correcta

set -e

# Definición de variables
PLUGIN_SLUG="mi-integracion-api"
PLUGIN_DIR=$(pwd)
BUILD_DIR="/tmp/${PLUGIN_SLUG}-build"
ZIP_FILE="${PLUGIN_SLUG}.zip"

# Verificar que estamos en el directorio correcto
if [ ! -f "${PLUGIN_DIR}/mi-integracion-api.php" ]; then
    echo "❌ Error: No se encuentra el archivo principal del plugin. Asegúrate de ejecutar este script desde el directorio raíz del plugin."
    exit 1
fi

# Ejecutar verificación pre-compilación
echo "🔍 Ejecutando verificación pre-compilación..."

# Ejecutar verificación del sistema unificado primero
if [ -f "${PLUGIN_DIR}/tools/verify-unified-system.sh" ]; then
    echo "✅ Verificando sistema unificado de configuración..."
    bash "${PLUGIN_DIR}/tools/verify-unified-system.sh"
    if [ $? -ne 0 ]; then
        echo "❌ Error: El sistema unificado tiene problemas. Por favor, revisa la implementación antes de compilar."
        exit 1
    fi
    echo "✅ Sistema unificado verificado correctamente."
fi

# Ejecutar verificación general si existe
if [ -f "${PLUGIN_DIR}/check-before-build.sh" ]; then
    echo "✅ Ejecutando script de verificación completo..."
    bash "${PLUGIN_DIR}/check-before-build.sh"
    if [ $? -ne 0 ]; then
        echo "❌ Error: La verificación pre-compilación ha encontrado errores. Por favor, resuelve los problemas antes de compilar."
        exit 1
    fi
    echo "✅ Verificación pre-compilación completada con éxito."
else
    echo "⚠️ Script de verificación no encontrado. Ejecutando verificaciones básicas..."
    
    # Verificación básica de archivos críticos del sistema unificado
    CRITICAL_FILES=(
        "includes/Core/ApiConnector.php"
        "includes/Core/REST_API_Handler.php"
        "includes/Sync/SyncManager.php"
        "includes/Assets.php"
        "assets/css/admin.css"
        "mi-integracion-api.php"
    )

    for file in "${CRITICAL_FILES[@]}"; do
        if [ ! -f "${PLUGIN_DIR}/$file" ]; then
            echo "⚠️ Advertencia: No se encuentra el archivo crítico $file"
        else
            # Verificar sintaxis PHP
            php -l "${PLUGIN_DIR}/$file" > /dev/null 2>&1
            if [ $? -eq 0 ]; then
                echo "✅ $file - Sintaxis OK"
            else
                echo "❌ $file - Error de sintaxis"
                exit 1
            fi
        fi
    done
fi

# Verificar si existen los templates
if [ ! -d "${PLUGIN_DIR}/templates" ]; then
    echo "⚠️ Advertencia: No se encuentra el directorio de templates. Los estilos podrían no aplicarse correctamente."
elif [ ! -f "${PLUGIN_DIR}/templates/admin/header.php" ] || [ ! -f "${PLUGIN_DIR}/templates/admin/footer.php" ]; then
    echo "⚠️ Advertencia: Faltan templates de cabecera o pie. Los estilos podrían no aplicarse correctamente."
fi

# Ejecutar verificador de selectores CSS si existe
if [ -f "${PLUGIN_DIR}/css-selector-check.sh" ]; then
    echo "🔍 Verificando coherencia de selectores CSS..."
    bash "${PLUGIN_DIR}/css-selector-check.sh" > "${PLUGIN_DIR}/css-selector-check.log"
    echo "✅ Verificación de selectores completada. Resultados guardados en css-selector-check.log"
fi

echo "🔍 Iniciando compilación del plugin ${PLUGIN_SLUG}..."

# Verificar si ya existe un archivo ZIP y obtener su tamaño para comparación
if [ -f "$PLUGIN_DIR/$ZIP_FILE" ]; then
    ORIGINAL_SIZE=$(du -h "$PLUGIN_DIR/$ZIP_FILE" | cut -f1)
    echo "📊 Tamaño del plugin actual: $ORIGINAL_SIZE"
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
cp composer.json "$BUILD_DIR/" || true
cp -r admin "$BUILD_DIR/"
cp -r api_connector "$BUILD_DIR/"
cp -r assets "$BUILD_DIR/"
cp -r includes "$BUILD_DIR/"
cp -r languages "$BUILD_DIR/"
cp -r lib "$BUILD_DIR/"

# Crear directorio de logs con permisos adecuados
mkdir -p "$BUILD_DIR/logs"
chmod 755 "$BUILD_DIR/logs"
echo "📂 Creado directorio de logs con permisos adecuados"

# Excluir archivos de diagnóstico/debug
echo "🔍 Excluyendo archivos de diagnóstico y desarrollo..."
if [ -f "debug-assets.php" ]; then
    echo "⚠️ debug-assets.php no se incluirá en el ZIP (archivo de diagnóstico)"
fi

# Copiar templates si existe
if [ -d "templates" ]; then
    echo "📂 Copiando templates para cabeceras y pies de página..."
    cp -r templates "$BUILD_DIR/"
else
    echo "⚠️ Directorio 'templates' no encontrado. Los templates de administración podrían faltar."
fi

# Copiar README.txt si existe (necesario para información del plugin en WordPress)
if [ -f "README.txt" ]; then
    cp README.txt "$BUILD_DIR/"
fi

# Eliminar archivos y carpetas innecesarios del build
echo "🧹 Limpiando archivos innecesarios..."

# Mantener docs y eliminar archivos no necesarios
if [ -d "$BUILD_DIR/docs" ]; then
    # Conservar documentación esencial para el usuario final
    echo "📚 Conservando documentación esencial para el usuario..."
    
    # Lista de documentación importante para el usuario final
    USER_DOCS=(
        "manual-usuario.md"
        "guia-instalacion.md"
        "guia-resolucion-problemas.md"
        "GUIA-TESTING-HOSTING.md"
        "sistema-unificado-configuracion.md"
    )
    
    # Crear directorio temporal para docs importantes
    mkdir -p "/tmp/docs-importantes"
    
    # Copiar documentación importante
    for doc in "${USER_DOCS[@]}"; do
        if [ -f "$BUILD_DIR/docs/$doc" ]; then
            cp "$BUILD_DIR/docs/$doc" "/tmp/docs-importantes/"
            echo "✅ Conservando: $doc"
        fi
    done
    
    # Eliminar todo el directorio docs
    rm -rf "$BUILD_DIR/docs"
    
    # Recrear directorio docs solo con documentación importante
    mkdir -p "$BUILD_DIR/docs"
    
    # Restaurar documentación importante
    if [ -d "/tmp/docs-importantes" ] && [ "$(ls -A /tmp/docs-importantes)" ]; then
        cp /tmp/docs-importantes/* "$BUILD_DIR/docs/"
        echo "✅ Documentación esencial restaurada"
    fi
    
    # Limpiar temporal
    rm -rf "/tmp/docs-importantes"
fi

# Guardar el resultado de la verificación de selectores en el directorio docs
if [ -f "${PLUGIN_DIR}/css-selector-check.log" ]; then
    echo "📝 Guardando informe de verificación de selectores en docs..."
    mkdir -p "$BUILD_DIR/docs"
    cp "${PLUGIN_DIR}/css-selector-check.log" "$BUILD_DIR/docs/verificacion-selectores.log"
fi

# Eliminar directorios y archivos de desarrollo
find "$BUILD_DIR" -name 'Legacy' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name '*.bak' -delete
find "$BUILD_DIR" -name '*.legacy' -delete
find "$BUILD_DIR" -name '*.sh' -delete
find "$BUILD_DIR" -name '*.log' -not -name 'verificacion-selectores.log' -delete
find "$BUILD_DIR" -name '.DS_Store' -delete
find "$BUILD_DIR" -name '__MACOSX' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name 'tests' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name 'scripts' -type d -exec rm -rf {} \; 2>/dev/null || true

# Eliminar herramientas de desarrollo pero conservar documentación esencial del sistema unificado
if [ -d "$BUILD_DIR/tools" ]; then
    echo "📚 Conservando documentación esencial del sistema unificado..."
    # Crear directorio docs si no existe
    mkdir -p "$BUILD_DIR/docs"
    
    # Conservar documentación importante para el usuario final
    if [ -f "$BUILD_DIR/tools/unify-config-cleanup.php" ]; then
        cp "$BUILD_DIR/tools/unify-config-cleanup.php" "$BUILD_DIR/docs/migracion-configuracion.php"
        echo "✅ Script de migración incluido en docs/"
    fi
    
    # Eliminar el directorio tools completo
    rm -rf "$BUILD_DIR/tools"
fi

# No eliminar README.txt ya que es necesario para WordPress
find "$BUILD_DIR" -name '*.txt' -not -name 'README.txt' -delete

# Eliminar archivos de documentación internos que no deben incluirse en la versión publicada
INTERNAL_DOCS=(
    "README-SOLUCION.md"
    "INSTRUCCIONES-RAPIDAS.md"
    "ESTADO-FINAL-COMPLETO.md"
    "RESUMEN-FINAL-UNIFICACION.md"
)

for doc in "${INTERNAL_DOCS[@]}"; do
    if [ -f "$BUILD_DIR/$doc" ]; then
        echo "🗑️ Eliminando documentación interna: $doc"
        rm "$BUILD_DIR/$doc"
    fi
done

# Optimizar vendor para reducir tamaño
if [ -f "$BUILD_DIR/composer.json" ]; then
    echo "📦 Instalando dependencias de producción optimizadas..."
    # Entrar al directorio build y ejecutar composer install solo para producción
    cd "$BUILD_DIR"
    composer install --no-dev --optimize-autoloader --quiet || echo "⚠️ No se pudieron instalar las dependencias. El plugin podría no funcionar correctamente."
    
    # Limpiar archivos innecesarios del vendor para reducir tamaño
    echo "🗑️ Reduciendo tamaño de la carpeta vendor..."
    if [ -d "$BUILD_DIR/vendor" ]; then
        # Eliminar archivos de documentación, test y desarrollo
        find "$BUILD_DIR/vendor" -type d -name "doc" -exec rm -rf {} \; 2>/dev/null || true
        find "$BUILD_DIR/vendor" -type d -name "docs" -exec rm -rf {} \; 2>/dev/null || true
        find "$BUILD_DIR/vendor" -type d -name "test" -exec rm -rf {} \; 2>/dev/null || true
        find "$BUILD_DIR/vendor" -type d -name "tests" -exec rm -rf {} \; 2>/dev/null || true
        find "$BUILD_DIR/vendor" -type d -name ".github" -exec rm -rf {} \; 2>/dev/null || true
        find "$BUILD_DIR/vendor" -name "*.md" -delete
        find "$BUILD_DIR/vendor" -name "*.txt" -not -name "LICENSE.txt" -delete
        find "$BUILD_DIR/vendor" -name "phpunit.*" -delete
        find "$BUILD_DIR/vendor" -name ".travis.yml" -delete
        find "$BUILD_DIR/vendor" -name ".gitignore" -delete
    fi
    cd - > /dev/null
    
    # Mostrar tamaño del vendor optimizado
    if [ -d "$BUILD_DIR/vendor" ]; then
        VENDOR_SIZE=$(du -sh "$BUILD_DIR/vendor" | cut -f1)
        echo "📊 Tamaño de vendor optimizado: $VENDOR_SIZE"
    fi
else
    # Si no hay composer.json, eliminar vendor si existe
    find "$BUILD_DIR" -name 'vendor' -type d -exec rm -rf {} \; 2>/dev/null || true
fi

# Entrar al directorio build y crear el ZIP desde ahí para tener la estructura correcta
echo "🔒 Creando archivo ZIP..."
cd "$BUILD_DIR"
zip -r "$ZIP_FILE" ./* > /dev/null

# Verificar si el ZIP se creó correctamente
if [ ! -f "$ZIP_FILE" ]; then
    echo "❌ Error: No se pudo crear el archivo ZIP. Verifica que tienes permisos y espacio suficiente."
    exit 1
fi

# Mover el ZIP al directorio original, al escritorio y al pendrive (si está montado)
echo "🚚 Moviendo el archivo ZIP a las ubicaciones finales..."
cp "$ZIP_FILE" "$HOME/Escritorio/$ZIP_FILE"
mv "$ZIP_FILE" "$PLUGIN_DIR/$ZIP_FILE"

# Intentar detectar y copiar al primer pendrive montado en /media o /run/media
PENDRIVE_MOUNT=""
if mount | grep -q "/media/"; then
    PENDRIVE_MOUNT=$(lsblk -o MOUNTPOINT | grep "/media/" | head -n1 | xargs)
fi
if [ -z "$PENDRIVE_MOUNT" ] && mount | grep -q "/run/media/"; then
    PENDRIVE_MOUNT=$(lsblk -o MOUNTPOINT | grep "/run/media/" | head -n1 | xargs)
fi
if [ -n "$PENDRIVE_MOUNT" ] && [ -d "$PENDRIVE_MOUNT" ]; then
    cp "$PLUGIN_DIR/$ZIP_FILE" "$PENDRIVE_MOUNT/$ZIP_FILE"
    echo "✅ Copia adicional guardada en el pendrive: $PENDRIVE_MOUNT/$ZIP_FILE"
else
    echo "ℹ️ No se detectó pendrive montado en /media o /run/media. Solo se copió a Escritorio y proyecto."
fi

# Verificar que las copias se hicieron correctamente
if [ ! -f "$HOME/Escritorio/$ZIP_FILE" ] || [ ! -f "$PLUGIN_DIR/$ZIP_FILE" ]; then
    echo "⚠️ Advertencia: No se pudieron copiar los archivos a las ubicaciones finales."
fi

# Limpiar
cd - > /dev/null
rm -rf "$BUILD_DIR"

# Mostrar tamaño del archivo ZIP
ZIP_SIZE=$(du -h "$PLUGIN_DIR/$ZIP_FILE" | cut -f1)

echo ""
echo "✅ PLUGIN COMPILADO EXITOSAMENTE"
echo "================================="
echo "📁 Ubicaciones:"
echo "   - Escritorio: $HOME/Escritorio/$ZIP_FILE"
echo "   - Proyecto: $PLUGIN_DIR/$ZIP_FILE"
echo "📊 Información:"
echo "   - Tamaño: $ZIP_SIZE"
echo "   - Fecha: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""
echo "🎯 SISTEMA UNIFICADO INCLUIDO:"
echo "   ✅ Configuración unificada implementada"
echo "   ✅ Compatibilidad con versiones anteriores"
echo "   ✅ Script de migración incluido en docs/"
echo "   ✅ Documentación de usuario incluida"
echo ""
echo "📋 CONTENIDO DEL PLUGIN:"
if [ -d "$PLUGIN_DIR/includes/Core" ]; then
    CORE_FILES=$(find "$PLUGIN_DIR/includes/Core" -name "*.php" | wc -l)
    echo "   - $CORE_FILES archivos principales del sistema unificado"
fi
if [ -d "$PLUGIN_DIR/docs" ]; then
    DOC_COUNT=$(find "$PLUGIN_DIR/docs" -name "*.md" | wc -l)
    echo "   - Documentación para el usuario (archivos esenciales)"
fi
echo "   - Sistema de configuración unificado"
echo "   - Compatibilidad con configuraciones existentes"
echo ""
echo "🚀 LISTO PARA INSTALACIÓN EN WORDPRESS"
echo "================================="
