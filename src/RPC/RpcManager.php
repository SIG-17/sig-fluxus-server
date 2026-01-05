<?php

namespace SIG\Server\RPC;

use Psr\Log\LoggerInterface;
use SIG\Server\Collection\MethodsCollection;
use SIG\Server\Config\MethodConfig;
use SIG\Server\Exception\InvalidArgumentException;
use SIG\Server\Exception\UnexpectedValueException;
use SIG\Server\Fluxus;
use SIG\Server\Protocol\Request\Action;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use SIG\Server\Protocol\Response\Type;
use SIG\Server\Protocol\Status;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class RpcManager implements RpcManagerInterface
{

    public Table $rpcMethods;
    public Table $rpcRequests;
    private int $rpcRequestCounter = 0;
    public array $rpcHandlers = [];
    private array $rpcMetadata = [];
    private array $rpcInternalProcessors = [];
    private array $rpcMethodsQueue = [];

    public function __construct(
        private readonly Fluxus           $server,
        public readonly Action            $protocol,
        public readonly Type              $responses,
        private readonly ?LoggerInterface $logger = null
    )
    {

    }

    public function initializeOnStart(): void
    {
        $this->initRpcTables();
        $this->initializeRpcInternalProcessors();
        $this->registerRpcHandlers();
        $this->registerHttpRequestHandlers();
    }

    public function initializeOnWorkers()
    {
        $this->initRpcTables();
        $this->initializeRpcInternalProcessors();
        $this->registerRpcHandlers();
        $this->registerHttpRequestHandlers();

    }

    public function cleanUpResources()
    {
        $this->cleanUpRpcProcessors();
        $this->cleanUpRpcTables();
    }

    private function initRpcTables(): void
    {
        $this->rpcMethods = new Table(512);
        $this->rpcMethods->column('name', Table::TYPE_STRING, 128);
        $this->rpcMethods->column('description', Table::TYPE_STRING, 255);
        $this->rpcMethods->column('requires_auth', Table::TYPE_INT, 1);
        $this->rpcMethods->column('registered_by_worker', Table::TYPE_INT);
        $this->rpcMethods->column('registered_at', Table::TYPE_INT);
        $this->rpcMethods->column('allowed_roles', Table::TYPE_STRING, 4096);
        $this->rpcMethods->column('only_internal', Table::TYPE_INT, 1);
        $this->rpcMethods->column('coroutine', Table::TYPE_INT, 1);
        $this->rpcMethods->create();
        while ($config = array_shift($this->rpcMethodsQueue)) {
            if (is_array($config)) {
                $this->logger?->debug("Intentando registrar " . var_export($config, true));
            }
            $this->registerRpcMethod($config);
        }

        $this->rpcRequests = new Table(1024);
        $this->rpcRequests->column('request_id', Table::TYPE_STRING, 32);
        $this->rpcRequests->column('fd', Table::TYPE_INT);
        $this->rpcRequests->column('method', Table::TYPE_STRING, 128);
        $this->rpcRequests->column('params', Table::TYPE_STRING, 4096); // JSON
        $this->rpcRequests->column('created_at', Table::TYPE_INT);
        $this->rpcRequests->column('status', Table::TYPE_STRING, 20); // pending, processing, completed, failed
        $this->rpcRequests->create();
    }

    private function registerRpcHandlers(): void
    {
        /*  if ($data['action'] === 'rpc' && isset($data['method'])) {
              $this->handleBroadcastRpc($data, $srcWorkerId);
          }

          if ($data['action'] === 'broadcast_collect') {
              $this->handleBroadcastCollect($data, $srcWorkerId);
          }
          if ($data['action'] === 'collect_response') {
              $this->handleCollectResponse($server, $data, $srcWorkerId);
          }*/
        $this->server->registerMessageHandler('rpc', [$this, 'handleMessages']);

        $this->server->registerPipeMessageHandler('rpc', [$this, 'handleBroadcastRpc']);
        $this->server->registerPipeMessageHandler('broadcast_collect', [$this, 'handleBroadcastCollect']);
        $this->server->registerPipeMessageHandler('collect_response', [$this, 'handleCollectResponse']);

        $this->server->registerTaskHandler('broadcast_task', [$this, 'handleBroadcastTask']);

    }
    public function handleMessages(Server $server, Frame $frame)
    {

        $data = json_decode($frame->data, true);
        if (!$data || !isset($data['action'])) {
            $server->sendError($frame->fd, 'El mensaje no puede procesarse');
            return;
        }
        $workerId = $server->getWorkerId();
        $this->logger?->debug("Mensaje recibido en worker #{$workerId} de FD {$frame->fd}: " . $frame->data);
        $protocol = $this->protocol->getProtocolFor($data);
        $this->logger?->debug("Protocolo de solicitud: " . get_class($protocol));
        if ($protocol instanceof RequestHandlerInterface) {
            $protocol->handle($frame->fd, $this->server);
        } else {
            $server->sendError($frame->fd, 'Acción no reconocida: ' . $data['action']);
        }
    }
    public function handleBroadcastTask(Server $server, Frame $frame, int $srcWorkerId): string
    {

        if (isset($data['method']) && isset($this->rpcHandlers[$data['method']])) {
            $message = json_encode([
                'action' => 'rpc',
                'method' => $data['method'],
                'params' => $data['params'] ?? [],
                'timestamp' => time()
            ], JSON_THROW_ON_ERROR);

            $totalWorkers = $server->setting['worker_num'] ?? 1;
            for ($i = 0; $i < $totalWorkers; $i++) {
                $server->sendMessage($message, $i);
            }
            return 'Broadcast task processed. Sent to all workers (' . $totalWorkers . ')';
        }
        return 'No RPC method found';
    }
    private function registerHttpRequestHandlers(): void
    {
        $this->server->registerRequestHandler('/api/rpc', function ($request, $response) {
            $response->header('Content-Type', 'application/javascript');
            $response->header('Access-Control-Allow-Origin', '*');
            $methods = $this->exposeRpcMethods();
            $js = $this->generateJavascriptStubs($methods);
            $response->end($js);
        });
        $this->server->registerRequestHandler('/api/rpc-docs', function ($request, $response) {
            $response->header('Content-Type', 'text/html; charset=utf-8');
            $response->header('Access-Control-Allow-Origin', '*');

            $html = file_get_contents(__DIR__ . '/rpc-docs.html');

            $response->end(str_replace(['{{host}}', '{{port}}'], [$this->server->host, $this->server->port], $html));
        });
        $this->server->registerRequestHandler('/api/rpc-methods', function ($request, $response) {
            $response->header('Content-Type', 'application/json');
            $response->header('Access-Control-Allow-Origin', '*');
            $response->end($this->generateJsonMethodsDescription());
        });
        $this->server->registerRequestHandler('/js/VecturaPubSub.js', function ($request, $response) {
            $response->header('Content-Type', 'application/javascript');
            $response->header('Access-Control-Allow-Origin', '*');
            $response->end(file_get_contents(__DIR__ . '/../../js/VecturaPubSub.js'));
        });
        /*
            case '/api/rpc':
                        $response->header('Content-Type', 'application/javascript');
                        $response->header('Access-Control-Allow-Origin', '*');

                        $methods = $this->exposeRpcMethods();
                        $js = $this->generateJavascriptStubs($methods);
                        $response->end($js);
                        break;
                    case '/api/rpc-docs':
                        $response->header('Content-Type', 'text/html; charset=utf-8');
                        $response->header('Access-Control-Allow-Origin', '*');

                        $html = file_get_contents(__DIR__ . '/rpc-docs.html');

                        $response->end(str_replace(['{{host}}', '{{port}}'], [$this->host, $this->port], $html));/
                        break;
                    case '/api/rpc-methods':
                        $response->header('Content-Type', 'application/json');
                        $response->header('Access-Control-Allow-Origin', '*');
                        $response->end($this->generateJsonMethodsDescription());
                        break;
                    case '/js/VecturaPubSub.js':
                        $response->header('Content-Type', 'application/javascript');
                        $response->header('Access-Control-Allow-Origin', '*');
                        $response->end(file_get_contents(__DIR__ . '/../js/VecturaPubSub.js'));
                        break;*/
    }
    // En tu Fluxus class, añade este método:
    public function exposeRpcMethods(): array
    {
        $methods = [];
        foreach ($this->getRpcMethodsInfo() as $methodInfo) {
            if ($methodInfo['only_internal'] ?? 0) {
                continue;
            }
            $methods[$methodInfo['name']] = $methodInfo;
        }

        return $methods;
    }

    private function generateJsonMethodsDescription(): string
    {
        $json = [
            'server' => $this->server->getServerId(),
            'date' => date('Y-m-d H:i:s'),
            'type' => 'rpc',
            'methods' => [],
        ];
        $methods = $this->exposeRpcMethods();
        foreach ($methods as $name => $method) {
            $params = array_values(array_filter($method['parameters'] ?? [], static fn($param) => (bool)$param['injected'] !== true && $param['type'] !== 'closure'));
            $json['methods'][] = [
                'method' => $name,
                'description' => $method['description'] ?? '',
                'params' => $params,
                'allow_guest' => !($method['requires_auth'] ?? true)
            ];
        }

        return json_encode($json);
    }

    private function generateJavascriptStubs(array $methods): string
    {
        $js = <<<JS
// Auto-generated RPC client for Fluxus WebSocket server
// Generated at: {date}
// Server: {serverId}
class VecturaRPC {
    constructor(wsUrl, options = {}) {
        this.wsUrl = wsUrl;
        this.options = {
            autoReconnect: true,
            reconnectDelay: 3000,
            maxReconnectAttempts: 5,
            requestTimeout: 30000,
            ...options
        };
        
        this.ws = null;
        this.connected = false;
        this.pendingRequests = new Map();
        this.reconnectAttempts = 0;
        this.messageQueue = [];
        this.requestId = 1;
        
        this.eventHandlers = {
            open: [],
            close: [],
            error: [],
            message: []
        };
        
        this.init();
    }
    
    init() {
        this.connect();
    }
    
    connect() {
        try {
            this.ws = new WebSocket(this.wsUrl);
            this.setupWebSocket();
        } catch (error) {
            this.emit('error', error);
            this.scheduleReconnect();
        }
    }
    
    setupWebSocket() {
        this.ws.onopen = (event) => {
            this.connected = true;
            this.reconnectAttempts = 0;
            this.emit('open', event);
            
            // Procesar cola de mensajes pendientes
            this.processMessageQueue();
        };
        
        this.ws.onclose = (event) => {
            this.connected = false;
            this.emit('close', event);
            
            if (this.options.autoReconnect && 
                this.reconnectAttempts < this.options.maxReconnectAttempts) {
                this.scheduleReconnect();
            }
        };
        
        this.ws.onerror = (error) => {
            this.emit('error', error);
        };
        
        this.ws.onmessage = (event) => {
            this.handleMessage(event.data);
        };
    }
    
    scheduleReconnect() {
        if (this.reconnectAttempts >= this.options.maxReconnectAttempts) {
            return;
        }
        
        this.reconnectAttempts++;
        const delay = this.options.reconnectDelay * this.reconnectAttempts;
        
        setTimeout(() => {
            if (!this.connected) {
                this.connect();
            }
        }, delay);
    }
    
    handleMessage(data) {
        try {
            const message = JSON.parse(data);
            
            // Manejar respuestas RPC
            if (message.type === 'rpc_response' && message.id) {
                const request = this.pendingRequests.get(message.id);
                if (request) {
                    if(message.status === 'accepted') return;
                    if (message.status === 'success') {
                        request.resolve(message.result);
                    } else {
                        request.reject(new Error(message.error?.message || 'RPC Error'));
                    }
                    
                    clearTimeout(request.timeoutId);
                    this.pendingRequests.delete(message.id);
                }
                return;
            }
            
            // Manejar errores RPC
            if (message.type === 'rpc_error' && message.id) {
                const request = this.pendingRequests.get(message.id);
                if (request) {
                    request.reject(new Error(message.error?.message || 'RPC Error'));
                    clearTimeout(request.timeoutId);
                    this.pendingRequests.delete(message.id);
                }
                return;
            }
            
            // Emitir otros mensajes
            this.emit('message', message);
            
        } catch (error) {
            console.error('Error parsing message:', error, data);
        }
    }
    
    sendRpc(method, params = {}) {
        return new Promise((resolve, reject) => {
            if (!this.connected) {
                // Encolar si no estamos conectados
                this.messageQueue.push({ method, params, resolve, reject });
                reject(new Error('WebSocket not connected'));
                return;
            }
            
            const requestId = `rpc_\${Date.now()}_\${this.requestId++}`;
            const message = {
                action: 'rpc',
                id: requestId,
                method: method,
                params: params,
                timestamp: Date.now()
            };
            
            // Configurar timeout
            const timeoutId = setTimeout(() => {
                if (this.pendingRequests.has(requestId)) {
                    this.pendingRequests.delete(requestId);
                    reject(new Error(`RPC timeout for method: \${method}`));
                }
            }, this.options.requestTimeout);
            
            // Guardar referencia a la promesa
            this.pendingRequests.set(requestId, { 
                resolve, 
                reject, 
                timeoutId,
                method,
                timestamp: Date.now()
            });
            
            // Enviar mensaje
            this.ws.send(JSON.stringify(message));
        });
    }
    
    processMessageQueue() {
        while (this.messageQueue.length > 0) {
            const { method, params, resolve, reject } = this.messageQueue.shift();
            this.sendRpc(method, params).then(resolve).catch(reject);
        }
    }
    
    // Event handling
    on(event, handler) {
        if (!this.eventHandlers[event]) {
            this.eventHandlers[event] = [];
        }
        this.eventHandlers[event].push(handler);
    }
    
    off(event, handler) {
        if (this.eventHandlers[event]) {
            this.eventHandlers[event] = this.eventHandlers[event].filter(h => h !== handler);
        }
    }
    
    emit(event, data) {
        if (this.eventHandlers[event]) {
            this.eventHandlers[event].forEach(handler => {
                try {
                    handler(data);
                } catch (error) {
                    console.error(`Error in \${event} handler:`, error);
                }
            });
        }
    }
    
    disconnect() {
        if (this.ws) {
            this.ws.close();
        }
        this.connected = false;
    }
}
// Métodos RPC disponibles
const RPC_METHODS = {methods};
console.log(RPC_METHODS)
// Crear stubs para cada método
Object.keys(RPC_METHODS).forEach(methodName => {
    console.log('Agregando handler para ', methodName);
    VecturaRPC.prototype[methodName] = function(...args) {
        // Manejar diferentes estilos de llamada
        let params = {};
        
        if (args.length === 1 && typeof args[0] === 'object') {
            // Estilo objeto: client.method({ param1: value1 })
            params = args[0];
        } else {
            // Estilo posicional: client.method(value1, value2)
            // Necesitarías documentar el orden de parámetros
            const methodInfo = RPC_METHODS[methodName];
            if (methodInfo.paramNames && methodInfo.paramNames.length === args.length) {
                methodInfo.paramNames.forEach((paramName, index) => {
                    params[paramName] = args[index];
                });
            } else {
                // Fallback: args como array
                params = { args: args };
            }
        }
        
        return this.sendRpc(methodName, params);
    };
});
// Exportar
window.VecturaRPC = VecturaRPC;
JS;
        // Reemplazar placeholders
        return str_replace(
            ['{date}', '{serverId}', '{methods}'],
            [
                date('Y-m-d H:i:s'),
                $this->server->getServerId(),
                json_encode($methods, JSON_THROW_ON_ERROR)
            ],
            $js
        );
    }

    public function getRpcMethods(): Table
    {
        return $this->rpcMethods;
    }

    /**
     * Envía resultado RPC al cliente
     * @throws UnexpectedValueException
     */
    private function sendRpcResult(int $fd, string $requestId, $result, float $executionTime): void
    {
        $this->server->sendProtocolResponse($this->protocol::getProtocolName(), 'rpcResponse', $fd, [
            'id' => $requestId,
            'status' => Status::success,
            'result' => $result,
            '_metadata' => [
                'execution_time' => $executionTime,
                'timestamp' => time()
            ]
        ]);
    }

    /**
     * Envía error RPC al cliente
     */
    public function sendRpcError(int $fd, string $requestId, string $error, int $code = 500): void
    {
        $this->logger?->error("Error RPC: $requestId ($error)");
        $this->server->sendProtocolResponse($this->protocol::getProtocolName(), 'rpcError', $fd, [
            'id' => $requestId,
            'status' => Status::error,
            'error' => [
                'code' => $code,
                'message' => $error
            ],
            '_metadata' => [
                'timestamp' => time()
            ]
        ]);
        $this->updateRpcRequest($requestId, 'failed');
    }

    /**
     * Actualiza estado de solicitud RPC
     */
    private function updateRpcRequest(string $requestId, string $status): void
    {
        if ($this->rpcRequests->exist($requestId)) {
            $request = $this->rpcRequests->get($requestId);
            $request['status'] = $status;
            $this->rpcRequests->set($requestId, $request);
        }
    }

    /**
     * Genera ID único para RPC
     */
    public function generateRpcId(): string
    {
        return uniqid('rpc_', false) . '_' . (++$this->rpcRequestCounter);
    }

    /**
     * Ejecuta un método RPC en una corutina separada con timeout
     * @throws InvalidArgumentException
     */
    public function executeRpcMethod(int $fd, string $requestId, string $method, array $params, int $timeout, int $workerId): void
    {
        $methodInfo = $this->getRpcMethodInfo($method);
        $coroutine = $methodInfo['coroutine'] ?? true;
        $parameters = [];
        if ($methodInfo['parameters']) {
            $trace = [];
            if (count($methodInfo['parameters']) === 1 && $methodInfo['parameters'][array_key_first($methodInfo['parameters'])]['name'] === 'paramsArray' && !isset($params['paramsArray'])) {
                $parameters = $params;
            } else {
                foreach ($methodInfo['parameters'] as $parameter) {
                    if ($parameter['name'] === 'fd') {
                        $parameters['fd'] = $params['fd'] ?? $fd;
                        continue;
                    }
                    if ($parameter['name'] === 'server') {
                        $parameters['server'] = $params['server'] ?? $this->server;
                        continue;
                    }
                    if ($parameter['name'] === 'workerId') {
                        $parameters['workerId'] = $params['workerId'] ?? $workerId;
                        continue;
                    }
                    if ($parameter['name'] === 'requestId') {
                        $parameters['requestId'] = $params['requestId'] ?? $requestId;
                        continue;
                    }
                    $required = $parameter['required'] ?? true;
                    $value = $params[$parameter['name']] ?? $parameter['default'];
                    if ($required && !$value) {
                        throw new InvalidArgumentException("Parameter '{$parameter['name']}' is required");
                    }
                    if ($value) {
                        $parameters[$parameter['name']] = $value;
                    }
                }
            }
        } else {
            $parameters = $params;
        }
        if (!$coroutine) {
            $this->execRpc($fd, $requestId, $method, $parameters, $workerId);
        } else {
            $this->execRpcCoroutine($fd, $requestId, $method, $parameters, $timeout, $workerId);
        }
    }

    /**
     * Registra una coroutine RPC para poder gestionarla
     */
    private function trackRpcCoroutine(string $requestId, int $coroutineId): void
    {
        $this->cancelableCids[] = $coroutineId;

        // Opcional: almacenar en tabla para mejor gestión
        if ($this->rpcRequests->exist($requestId)) {
            $request = $this->rpcRequests->get($requestId);
            $request['coroutine_id'] = $coroutineId;
            $this->rpcRequests->set($requestId, $request);
        }
    }

    /**
     * Limpia recursos de la coroutine
     */
    private function cleanupCoroutineResources(string $requestId): void
    {
        // Eliminar de la lista de cancelables
        if ($this->rpcRequests->exist($requestId)) {
            $request = $this->rpcRequests->get($requestId);
            if (isset($request['coroutine_id'])) {
                $coroutineId = $request['coroutine_id'];
                $this->server->cancelableCids = array_filter(
                    $this->server->cancelableCids,
                    static fn($cid) => $cid !== $coroutineId
                );
            }
        }
    }

    private function execRpcCoroutine(int $fd, string $requestId, string $method, array $params, int $timeout, int $workerId): void
    {
        $timeoutMs = $timeout * 1000;
        // Crear coroutine para ejecutar el RPC
        $coroutineId = Coroutine::create(function () use ($fd, $requestId, $method, $params, $timeoutMs, $workerId) {
            try {
                $this->logger?->debug("▶️  Iniciando coroutine para RPC en worker #{$workerId}: $method (ID: $requestId)" . ($timeoutMs > 0 ? " con timeout de {$timeoutMs}ms" : ""));
                // Verificar que el método existe
                if (!isset($this->rpcHandlers[$method])) {
                    $this->logger?->error("💥 Método desapareció durante ejecución en worker #{$workerId}: $method");
                    $this->sendRpcError($fd, $requestId, "Error interno: método no disponible", 500);
                    $this->updateRpcRequest($requestId, 'failed');
                    return;
                }
                // Actualizar estado a procesando
                $this->updateRpcRequest($requestId, 'processing');

                $startTime = microtime(true);
                $handler = $this->rpcHandlers[$method];
                $metadata = $this->rpcMetadata[$method];
                $timedOut = false;

                // Si hay timeout, usar un temporizador
                if ($timeoutMs > 0) {
                    $timeoutChannel = new Channel(1);
                    $timeoutTimerId = swoole_timer_after($timeoutMs, function () use (&$timedOut, $timeoutChannel, $requestId, $workerId) {
                        $timedOut = true;
                        $this->logger?->warning("⏰ Timeout alcanzado para RPC $requestId en worker #$workerId");
                        $timeoutChannel->push(true);
                    });

                    // Ejecutar handler en otra coroutine
                    Coroutine::create(function () use ($handler, $params, $timeoutChannel, $method) {
                        try {
                            //$result = $handler($this, $params, $fd);
                            $result = $handler(...$params);

                            $timeoutChannel->push(['result' => $result, 'success' => true]);
                        } catch (\Throwable $e) {
                            $timeoutChannel->push(['error' => "Error executing method $method, {$e->getMessage()}", 'success' => false]);
                            $this->logger?->error("❌❌❌ Error executing method $method, {$e->getMessage()}");
                        }
                    });

                    // Esperar resultado o timeout
                    $channelResult = $timeoutChannel->pop();

                    // Cancelar timer si aún está activo
                    if ($timeoutTimerId && swoole_timer_exists($timeoutTimerId)) {
                        swoole_timer_clear($timeoutTimerId);
                    }

                    if ($timedOut) {
                        throw new \RuntimeException("Timeout de {$timeoutMs}ms alcanzado para método {$method}");
                    }

                    if ($channelResult['success'] === false) {
                        throw $channelResult['error'];
                    }

                    $result = $channelResult['result'];
                } else {
                    // Sin timeout
                    $result = $handler(...$params);
                }

                $executionTime = round((microtime(true) - $startTime) * 1000, 2);

                // Agregar metadata al resultado
                if (is_array($result)) {
                    if (!isset($result['_metadata'])) {
                        $result['_metadata'] = [];
                    }
                    $result['_metadata'] = array_merge($result['_metadata'], [
                        'execution_time_ms' => $executionTime,
                        'worker_id' => $workerId,
                        'request_id' => $requestId,
                        'executed_in_coroutine' => true,
                        'timeout_ms' => $timeoutMs
                    ]);
                }

                // Enviar resultado
                $this->sendRpcResult($fd, $requestId, $result, $executionTime);
                $this->updateRpcRequest($requestId, 'completed');

                $this->logger?->debug("✅ RPC completado en coroutine worker #{$workerId}: $method en {$executionTime}ms");

            } catch (\Exception $e) {
                $this->logger?->error("❌ Error ejecutando RPC en coroutine worker #{$workerId}: " . $e->getMessage());
                $this->sendRpcError($fd, $requestId, $e->getMessage());
                $this->updateRpcRequest($requestId, 'failed');
            } finally {
                // Limpiar si es necesario
                $this->cleanupCoroutineResources($requestId);
            }
        });
        // Registrar la coroutine para poder cancelarla si es necesario
        $this->trackRpcCoroutine($requestId, $coroutineId);

        $this->logger?->debug("🚀 Coroutine creada para RPC $requestId (CID: $coroutineId)");
    }

    /**
     * Ejecuta un método RPC en una corutina
     */
    private function execRpc(int $fd, string $requestId, string $method, array $params, int $workerId): void
    {
        try {
            $this->logger?->debug("▶️  Ejecutando RPC en worker #{$workerId}: $method (ID: $requestId)");
            // Verificar que el método existe
            if (!isset($this->rpcHandlers[$method])) {
                $this->logger?->error("💥 Método desapareció durante ejecución en worker #{$workerId}: $method");
                $this->sendRpcError($fd, $requestId, "Error interno: método no disponible", 500);
                $this->updateRpcRequest($requestId, 'failed');
                return;
            }

            // Actualizar estado a procesando
            $this->updateRpcRequest($requestId, 'processing');

            $startTime = microtime(true);
            $handler = $this->rpcHandlers[$method];

            try {
                // Ejecutar handler
                $result = $handler(...$params);
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);
                // Agregar metadata al resultado
                if (is_array($result)) {
                    if (!isset($result['_metadata'])) {
                        $result['_metadata'] = [];
                    }
                    $result['_metadata'] = array_merge($result ['_metadata'], [
                        'execution_time_ms' => $executionTime,
                        'worker_id' => $workerId,
                        'request_id' => $requestId
                    ]);
                }

                // Enviar resultado
                $this->sendRpcResult($fd, $requestId, $result, $executionTime);
                $this->updateRpcRequest($requestId, 'completed');

                $this->logger?->debug("✅ RPC completado en worker #{$workerId}: $method en {$executionTime}ms");

            } catch (\Exception $e) {
                $this->logger?->error("❌ Error ejecutando RPC en worker #{$workerId}: " . $e->getMessage());
                $this->sendRpcError($fd, $requestId, $e->getMessage());
                $this->updateRpcRequest($requestId, 'failed');
            }

        } catch (\Exception $e) {
            $this->logger?->error("💥 Error crítico en RPC worker #{$workerId}: " . $e->getMessage());
            $this->sendRpcError($fd, $requestId, '💥 Error interno del servidor ' . $e->getMessage());
            $this->updateRpcRequest($requestId, 'failed');
        }
    }

    /**
     * Registra un método RPC (puede ser llamado por múltiples workers)
     */
    public function registerRpcMethod(MethodConfig $method): bool
    {
        $workerId = $this->server->getWorkerId();
        if (!isset($this->rpcHandlers)) {
            $this->rpcHandlers = [];
        }
        if (!isset($this->rpcMethods)) {
            $this->rpcMethodsQueue[] = $method;
            return true;
        }

        // Verificar solo si YA ESTÁ REGISTRADO EN ESTE WORKER
        if (isset($this->rpcHandlers[$method->method])) {
            $this->logger?->debug("Método $method->method ya registrado en worker #$workerId");
            return false;
        }

        // Guardar handler en ESTE worker
        $this->rpcHandlers[$method->method] = $method->handler;
        $roles = !empty($method->allowed_roles) ? implode('|', $method->allowed_roles) : '';
        // Solo el primer worker que encuentre el método vacío en la tabla lo registra
        if (!$this->rpcMethods->exist($method->method)) {
            $this->rpcMethods->set($method->method, [
                'name' => $method->method,
                'description' => $method->description,
                'requires_auth' => $method->requires_auth ? 1 : 0,
                'allowed_roles' => $roles,
                'registered_by_worker' => $workerId,
                'only_internal' => $method->only_internal ? 1 : 0,
                'coroutine' => $method->coroutine ? 1 : 0,
                'registered_at' => time()
            ]);
            // Guardar metadatos
            $this->rpcMetadata[$method->method] = [
                'parameters' => isset($method->parameters) ? $method->parameters->toArray() : [],
                'returns' => $method->returns,
                'description' => $method->description,
                'examples' => $method->examples, // Podrías añadir ejemplos
            ];
            $this->logger?->debug("✅ Método RPC registrado en tabla por worker #$workerId: $method->method para los roles $roles");
        } else {
            $this->logger?->debug("📝 Método $method->method ya en tabla, solo registrando handler en worker #$workerId");
        }

        return true;
    }

    public function registerRpcMethods(MethodsCollection $collection): void
    {
        foreach ($collection as $method) {
            $this->registerRpcMethod($method);
        }
    }

    public function getRpcMethodInfo(string $methodName): array
    {
        if (!$this->rpcMethods->exist($methodName)) {
            return [];
        }
        $baseData = $this->rpcMethods->get($methodName);
        $info = array_merge($baseData, $this->rpcMetadata[$methodName] ?? []);
        $info['allowed_roles'] = !empty($info['allowed_roles']) ? explode('|', $info['allowed_roles']) : [];
        $info['requires_auth'] = (bool)($info['requires_auth'] ?? false);
        return $info;
    }

    public function getRpcMethodsInfo(): array
    {
        $info = [];
        foreach ($this->rpcMethods as $method) {
            $info[] = $this->getRpcMethodInfo($method['name']);
        }
        return $info;
    }

    public function registerInternalRpcProcessor(string $processorName, RpcInternalProcessorInterface $processor): void
    {
        if (isset($this->rpcInternalProcessors[$processorName]) && $this->rpcInternalProcessors[$processorName] === $processor) {
            $this->logger?->warning('Processor ' . $processorName . ' already registered as internal RPC processor. Skipping...');
            return;
        }
        $this->logger?->debug('🥌 Registering internal RPC processor ' . $processorName);
        if ($this->server->isRunning()) {
            $processor->init($this->server);
        }
        $this->rpcInternalProcessors[$processorName] = $processor;
        if ($processor->publishRpcMethods($this->server)) {
            $this->logger?->debug('🥌 -> Registering RPC methods for internal RPC processor ' . $processorName);
            $this->registerRpcMethods($processor->publishRpcMethods($this->server));
        }
    }

    public function getInternalRpcProcessor(string $processorName): ?RpcInternalProcessorInterface
    {
        return $this->rpcInternalProcessors[$processorName] ?? null;
    }

    public function listInternalRpcProcessors(): array
    {
        return array_keys($this->rpcInternalProcessors);
    }

    private function initializeRpcInternalProcessors(): void
    {
        foreach ($this->rpcInternalProcessors as $processor) {
            $processor->init($this->server);
        }
    }

    private function cleanUpRpcProcessors(string $logPrefix = '🛑'): void
    {
        $workerId = $this->server->getWorkerId() ?? $this->server->workerId;
        $this->logger?->debug("$logPrefix Worker #$workerId: Limpiando procesadores RPC..." . var_export($workerId, true));
        foreach ($this->rpcInternalProcessors as $processor) {
            if ($processor instanceof RpcInternalProcessorInterface) {
                $processor->deInit($this->server);
            }
        }
    }

    private function cleanUpRpcTables(string $logPrefix = '🛑'): void
    {
        //$this->rpcMethods->destroy();
        //$this->rpcRequests->destroy();
        $this->logger?->info("$logPrefix Tablas RPC eliminadas");
    }


    public function handleCollectResponse(Server $server, array $data, int $srcWorkerId): void
    {
        $requestId = $data['request_id'] ?? '';
        $workerId = $data['worker_id'] ?? $srcWorkerId;

        if (empty($requestId)) {
            return;
        }

        // Guardar respuesta
        if (!isset($server->collectResponses[$requestId])) {
            $server->collectResponses[$requestId] = [];
        }

        $server->collectResponses[$requestId][$workerId] = [
            'data' => $data['data'] ?? ($data['error'] ?? 'No data'),
            'success' => $data['success'] ?? false,
            'timestamp' => $data['timestamp'] ?? time(),
            'source_worker' => $srcWorkerId
        ];

        // Notificar al canal si existe
        if (isset($server->collectChannels[$requestId])) {
            $server->collectChannels[$requestId]->push($workerId);
        }

        $this->logger?->debug("📥 Collect response recibido y almacenado de worker #{$workerId}");
    }

    /**
     * Maneja solicitudes de recolección broadcast
     */
    public function handleBroadcastCollect(array $data, int $srcWorkerId): void
    {
        $action = $data['collect_action'] ?? '';
        $params = $data['params'] ?? [];
        $requestId = $data['request_id'] ?? '';
        $responseToWorker = $data['response_to_worker'] ?? $srcWorkerId;

        $currentWorkerId = $this->server->getWorkerId();

        $this->logger?->debug("📊 Collect request: {$action} para worker #{$currentWorkerId}");

        // Ejecutar la acción si existe
        if (isset($this->rpcHandlers[$action])) {
            try {
                $result = $this->rpcHandlers[$action]($this, $params, 0);
                $result['_collected_at'] = time();
                $result['_worker_id'] = $currentWorkerId;

                // Enviar respuesta al worker solicitante
                $response = json_encode([
                    'action' => 'collect_response',
                    'request_id' => $requestId,
                    'worker_id' => $currentWorkerId,
                    'data' => $result,
                    'success' => true,
                    'timestamp' => time()
                ], JSON_THROW_ON_ERROR);

                $this->server->sendMessage($response, $responseToWorker);

                $this->logger?->debug("✅ Collect response enviado para {$action} en worker #{$currentWorkerId}");;

            } catch (\Exception $e) {
                // Enviar error
                $errorResponse = json_encode([
                    'action' => 'collect_response',
                    'request_id' => $requestId,
                    'worker_id' => $currentWorkerId,
                    'error' => $e->getMessage(),
                    'success' => false,
                    'timestamp' => time()
                ], JSON_THROW_ON_ERROR);

                $this->server->sendMessage($errorResponse, $responseToWorker);
            }
        }
    }

    /**
     * Ejecuta RPC recibido por broadcast
     */
    public function handleBroadcastRpc(array $data, int $srcWorkerId): void
    {
        $method = $data['method'] ?? '';
        $params = $data['params'] ?? [];
        $currentWorkerId = $this->server->getWorkerId();
        $methodInfo = $this->getRpcMethodInfo($method);
        $fd = 0;
        $parameters = [];
        if ($methodInfo['parameters']) {
            $trace = [];
            if (count($methodInfo['parameters']) === 1 && $methodInfo['parameters'][array_key_first($methodInfo['parameters'])]['name'] === 'paramsArray' && !isset($params['paramsArray'])) {
                $parameters = $params;
            } else {
                foreach ($methodInfo['parameters'] as $parameter) {
                    if ($parameter['name'] === 'fd') {
                        $parameters['fd'] = $params['fd'] ?? $fd;
                        continue;
                    }
                    if ($parameter['name'] === 'server') {
                        $parameters['server'] = $params['server'] ?? $this->server;
                        continue;
                    }
                    if ($parameter['name'] === 'workerId') {
                        $parameters['workerId'] = $params['workerId'] ?? $currentWorkerId;
                        continue;
                    }
                    if ($parameter['name'] === 'requestId') {
                        $parameters['requestId'] = $data['request_id'] ?? $params['requestId'] ?? '';
                        continue;
                    }
                    $required = $parameter['required'] ?? true;
                    $value = $params[$parameter['name']] ?? $parameter['default'];
                    if ($required && !$value) {
                        throw new InvalidArgumentException("Parameter '{$parameter['name']}' is required");
                    }
                    $parameters[$parameter['name']] = $value;
                }
            }
        } else {
            $parameters = $params;
        }

        $this->logger?->info("🔁 Ejecutando RPC broadcast: {$method} en worker #{$currentWorkerId}");

        // Verificar si el método existe en este worker
        if (!isset($this->rpcHandlers[$method])) {
            $this->logger?->warning("⚠️ Método {$method} no disponible en worker #{$currentWorkerId}");
            return;
        }

        try {
            // Ejecutar el método (usar FD 0 o null para indicar broadcast interno)
            $result = $this->rpcHandlers[$method](...$parameters);

            $this->logger?->info("✅ RPC broadcast ejecutado: {$method} en worker #{$currentWorkerId}");

            // Opcional: Notificar al worker origen
            if (isset($data['need_response']) && $data['need_response']) {
                $response = json_encode([
                    'action' => 'rpc_response',
                    'request_id' => $data['request_id'] ?? '',
                    'worker_id' => $currentWorkerId,
                    'method' => $method,
                    'success' => true,
                    'timestamp' => time()
                ], JSON_THROW_ON_ERROR);
                $this->server->sendMessage($response, $srcWorkerId);
            }

        } catch (\Exception $e) {
            $this->logger?->error("❌ Error ejecutando RPC broadcast {$method}: " . $e->getMessage());
        }
    }

    public function broadcastHandler(Server $server, array $data = []): string
    {
        if (isset($data['method']) && isset($this->rpcHandlers[$data['method']])) {
            $message = json_encode([
                'action' => 'rpc',
                'method' => $data['method'],
                'params' => $data['params'] ?? [],
                'timestamp' => time()
            ], JSON_THROW_ON_ERROR);

            $totalWorkers = $server->setting['worker_num'] ?? 1;
            for ($i = 0; $i < $totalWorkers; $i++) {
                $server->sendMessage($message, $i);
            }
            return 'Broadst task processed. Sent to all workers (' . $totalWorkers . ')';
        }
        return 'No method found for required task';
    }

    public function runOnOpenConnection(Server $server, Request $request): void
    {
        // TODO: Implement runOnOpenConnection() method.
    }

    public function runOnCloseConnection(Server $server, int $fd): void
    {
        // TODO: Implement runOnCloseConnection() method.
    }
}