#!/bin/bash

# Script de control para WebSocket Server en macOS
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVER_SCRIPT="$SCRIPT_DIR/router.php"
PID_FILE="$SCRIPT_DIR/websocket-server.pid"

start_server() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if ps -p $PID > /dev/null 2>&1; then
            echo "❌ Servidor ya está ejecutándose (PID: $PID)"
            return 1
        else
            rm -f "$PID_FILE"
        fi
    fi

    echo "🚀 Iniciando servidor WebSocket..."
    php "$SERVER_SCRIPT" &
    SERVER_PID=$!
    echo $SERVER_PID > "$PID_FILE"
    echo "✅ Servidor iniciado con PID: $SERVER_PID"

    # Esperar a que se inicialice
    sleep 2
    if ps -p $SERVER_PID > /dev/null 2>&1; then
        echo "📡 Servidor WebSocket ejecutándose correctamente"
    else
        echo "❌ Error: Servidor no se pudo iniciar"
        rm -f "$PID_FILE"
        return 1
    fi
}

stop_server() {
    if [ ! -f "$PID_FILE" ]; then
        echo "❌ No se encontró archivo PID. Servidor probablemente no está ejecutándose."
        return 1
    fi

    PID=$(cat "$PID_FILE")
    echo "🛑 Deteniendo servidor (PID: $PID)..."

    # Intentar shutdown graceful primero
    kill -TERM $PID 2>/dev/null

    # Esperar máximo 10 segundos
    for i in {1..10}; do
        if ! ps -p $PID > /dev/null 2>&1; then
            rm -f "$PID_FILE"
            echo "✅ Servidor detenido gracefulmente"
            return 0
        fi
        sleep 1
    done

    # Forzar stop si no responde
    echo "⚠️  Servidor no respondió a TERM, forzando stop..."
    kill -KILL $PID 2>/dev/null
    rm -f "$PID_FILE"
    echo "✅ Servidor forzado a detenerse"
}

restart_server() {
    stop_server
    sleep 2
    start_server
}

status_server() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if ps -p $PID > /dev/null 2>&1; then
            echo "✅ Servidor ejecutándose (PID: $PID)"

            # Mostrar procesos hijos (workers)
            CHILD_PROCESSES=$(pgrep -P $PID)
            if [ -n "$CHILD_PROCESSES" ]; then
                echo "👷 Workers activos:"
                for child in $CHILD_PROCESSES; do
                    echo "   - PID: $child"
                done
            else
                echo "⚠️  No se detectaron workers activos"
            fi

            # Mostrar conexiones
            echo "🌐 Conexiones:"
            lsof -p $PID | grep TCP | awk '{print "   - " $9}'
        else
            echo "❌ PID file existe pero proceso no está ejecutándose"
            rm -f "$PID_FILE"
        fi
    else
        echo "❌ Servidor no está ejecutándose"
    fi
}

kill_all() {
    echo "💥 Matando todos los procesos relacionados..."
    pkill -f "php.*test.php" 2>/dev/null
    pkill -f "swoole" 2>/dev/null
    rm -f "$PID_FILE"
    echo "✅ Todos los procesos eliminados"
}

case "$1" in
    start)
        start_server
        ;;
    stop)
        stop_server
        ;;
    restart)
        restart_server
        ;;
    status)
        status_server
        ;;
    kill)
        kill_all
        ;;
    *)
        echo "Uso: $0 {start|stop|restart|status|kill}"
        echo ""
        echo "Comandos:"
        echo "  start   - Iniciar servidor WebSocket"
        echo "  stop    - Detener servidor gracefulmente"
        echo "  restart - Reiniciar servidor"
        echo "  status  - Ver estado del servidor"
        echo "  kill    - Matar todos los procesos forzadamente"
        exit 1
esac