#!/bin/bash
# scripts/install-systemd.sh

set -e

echo "Instalando servicio systemd para WebSocket Server..."

    # Check if an argument was provided
    if [ -z "$1" ]; then
      echo "Uso: $0 <path_to_env_file> (Configuración de entorno para la instalación)"
      exit 1
    fi

    ENV_FILE="$1"

    # Check if the environment file exists
    if [ ! -f "$ENV_FILE" ]; then
      echo "Error: No se encontró el archivo de entorno '$ENV_FILE'."
      exit 1
    fi



# Ruta base del proyecto
PROJECT_PATH="$(dirname "${SCRIPT_DIR}")"

# Cargar variables de entorno
source "$ENV_FILE"


# Variables
SERVICE_NAME=${WS_SERVICE_NAME:-"sig-ws"}
INSTALL_DIR=${WS_INSTALL_DIR:-"/var/www/sig-ws"}
SCRIPT=${WS_SCRIPT:-"router.php"}
SERVICE_FILE="/etc/systemd/system/$SERVICE_NAME.service"
USER=${WS_USER:-"sig"}
GROUP=${WS_GROUP:-"sig"}
CONFIG=${WS_CONFIG:-".env"}

# Verificar root
if [ "$EUID" -ne 0 ]; then
    echo "Por favor, ejecuta como root"
    exit 1
fi

# Crear usuario y directorios
if ! id "$USER" &>/dev/null; then
    useradd -r -s /bin/false -d "$INSTALL_DIR" "$USER"
fi

mkdir -p "$INSTALL_DIR"/{app,logs,scripts,conf}
chown -R "$USER:$GROUP" "$INSTALL_DIR"
chmod 755 "$INSTALL_DIR"

# Copiar archivos (asumiendo que estás en el directorio del proyecto)
cp "$PROJECT_PATH/app/$SCRIPT" "$INSTALL_DIR/app/"
cp -r "$PROJECT_PATH/config" "$INSTALL_DIR/"
cp -r "$PROJECT_PATH/vendor" "$INSTALL_DIR/"
cp "$PROJECT_PATH/systemd/start-server.sh" "$INSTALL_DIR/scripts/"
cp "$PROJECT_PATH/systemd/$CONFIG" "$INSTALL_DIR/.env"

chmod +x "$INSTALL_DIR/scripts/start-server.sh"

# Instalar archivo de servicio
cat > "$SERVICE_FILE" << EOF
[Unit]
Description=WebSocket Pub/Sub Server
After=network.target redis-server.service
Wants=redis-server.service

[Service]
Type=simple
User=$USER
Group=$GROUP
WorkingDirectory=$INSTALL_DIR
ExecStart=$INSTALL_DIR/scripts/start-server.sh
ExecReload=/bin/kill -HUP \$MAINPID
ExecStop=/bin/kill -TERM \$MAINPID
Restart=always
RestartSec=3
StandardOutput=journal
StandardError=journal
SyslogIdentifier=sig-ws

# Security
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=$INSTALL_DIR/logs

# Resources
LimitNOFILE=65536
LimitNPROC=4096

[Install]
WantedBy=multi-user.target
EOF

# Recargar systemd
systemctl daemon-reload

echo "✅ Servicio instalado: $SERVICE_FILE"
echo ""
echo "Comandos útiles:"
echo "  systemctl start $SERVICE_NAME"
echo "  systemctl stop $SERVICE_NAME"
echo "  systemctl restart $SERVICE_NAME"
echo "  systemctl status $SERVICE_NAME"
echo "  journalctl -u $SERVICE_NAME -f"