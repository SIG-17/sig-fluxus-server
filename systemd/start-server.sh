#!/bin/bash

# Script wrapper para systemd
set -e

# Configuración
BASE_DIR="$(dirname "${SCRIPT_DIR}")"
SCRIPT_DIR="$BASE_DIR/app"
LOG_DIR="$BASE_DIR/logs"
PID_FILE="$LOG_DIR/sig-ws.pid"
CONFIG_FILE="$BASE_DIR/.env"

# Cargar variables de entorno
if [ -f "$CONFIG_FILE" ]; then
    source "$CONFIG_FILE"
fi

# Variables con valores por defecto
WS_CONFIG=${WS_CONFIG:-"production"}
WS_HOST=${WS_HOST:-"0.0.0.0"}
WS_PORT=${WS_PORT:-"9501"}
WS_VERBOSE=${WS_VERBOSE:-""}

# Crear directorios si no existen
mkdir -p "$LOG_DIR"

# Construir comando
CMD="/usr/bin/php $SCRIPT_DIR/router.php"

# Agregar opciones
[ -n "$WS_CONFIG" ] && CMD="$CMD --ws-config=$WS_CONFIG"
[ -n "$WS_HOST" ] && CMD="$CMD -h $WS_HOST"
[ -n "$WS_PORT" ] && CMD="$CMD -p $WS_PORT"
[ -n "$WS_VERBOSE" ] && CMD="$CMD -$WS_VERBOSE"

# Log inicial
echo "$(date): Iniciando servidor WebSocket con configuración: $WS_CONFIG" >> "$LOG_DIR/systemd.log"
echo "$(date): Comando: $CMD" >> "$LOG_DIR/systemd.log"

# Ejecutar servidor
exec $CMD