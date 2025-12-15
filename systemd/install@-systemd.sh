#!/bin/bash

# Ruta base del proyecto
PROJECT_PATH="$(dirname "${SCRIPT_DIR}")"
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

# Copiar archivos (asumiendo que estás en el directorio del proyecto)
cp "$PROJECT_PATH/app/$SCRIPT" "$INSTALL_DIR/app/"
cp -r "$PROJECT_PATH/config" "$INSTALL_DIR/"
cp -r "$PROJECT_PATH/vendor" "$INSTALL_DIR/"

# /etc/systemd/system/sig-ws@.service

cat > "${SERVICE_FILE}" << EOF
[Unit]
Description=WebSocket Pub/Sub Server - Instancia %I
Documentation=https://github.com/tu-usuario/tu-repositorio
After=network.target redis-server.service
Wants=redis-server.service
Requires=network.target

[Service]
Type=simple
User=$USER
Group=$GROUP
WorkingDirectory=$INSTALL_DIR
ExecStart=/usr/bin/php $INSTALL_DIR/$SCRIPT --ws-config=%I
ExecReload=/bin/kill -HUP \$MAINPID
ExecStop=/bin/kill -TERM \$MAINPID
Restart=always
RestartSec=3
StandardOutput=journal
StandardError=journal
SyslogIdentifier=$SERVICE_NAME-%I

# Security settings
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=$INSTALL_DIR/logs
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes

# Resource limits
LimitNOFILE=65536
LimitNPROC=4096
LimitMEMLOCK=infinity

# Timeouts
TimeoutStartSec=30
TimeoutStopSec=30
TimeoutSec=30

# Environment
Environment="PATH=/usr/bin:/bin"
Environment="PHP_INI_SCAN_DIR=$INSTALL_DIR/conf"

[Install]
WantedBy=multi-user.target
EOF
