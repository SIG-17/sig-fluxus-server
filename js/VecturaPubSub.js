/**
 * A WebSocket-based publish/subscribe system that facilitates communication,
 * file transfer, and reconnections with advanced handling features.
 * Supports both EventEmitter pattern and classic Pub/Sub callbacks.
 */
export default class VecturaPubSub extends EventTarget {
    /**
     * Event types for the WebSocket connection.
     * @type {string}
     */
    EVENT_TYPE_MESSAGE = 'message';
    EVENT_TYPE_READY = 'ready';
    EVENT_TYPE_CLOSE = 'close';
    EVENT_TYPE_RECONNECTING = 'reconnecting';
    EVENT_TYPE_SUBSCRIBED = 'subscribed';
    EVENT_TYPE_UNSUBSCRIBED = 'unsubscribed';
    EVENT_TYPE_PUBLISHED = 'published';
    EVENT_TYPE_ERROR = 'error';
    EVENT_TYPE_RPC_RESPONSE = 'rpc_response';
    EVENT_TYPE_RPC_METHODS = 'rpc_methods';
    EVENT_TYPE_RPC_REQUEST = 'rpc_request';
    EVENT_TYPE_RPC_START_EXEC = 'rpc_start_execution';
    EVENT_TYPE_RPC_ACCEPTED = 'rpc_accepted';
    EVENT_TYPE_RPC_COMPLETED = 'rpc_completed';
    EVENT_TYPE_RPC_ABORTED = 'rpc_aborted';

    /**
     * Messages types
     * @type {string}
     */
    MESSAGE_TYPE_FILE_AVAILABLE = 'file_available';
    MESSAGE_TYPE_FILE_RESPONSE = 'file_response';
    MESSAGE_TYPE_RPC_RESPONSE = 'rpc_response';
    MESSAGE_TYPE_RPC_METHODS = 'rpc_methods_list';
    MESSAGE_TYPE_ERROR = 'error';
    MESSAGE_TYPE_MESSAGE = 'message';
    MESSAGE_TYPE_SUCCESS = 'success';

    /**
     * RPC status
     * @type {string}
     */
    RPC_STATUS_ACCEPTED = 'accepted';
    RPC_STATUS_REJECTED = 'rejected';
    RPC_STATUS_ERROR = 'error';
    RPC_STATUS_COMPLETED = 'success';

    /**
     * RPC actions
     * @type {string}
     */
    ACTION_LIST_RPC_METHODS = 'list_rpc_methods';
    ACTION_RPC_CALL = 'rpc';
    ACTION_SUBSCRIBE = 'subscribe';
    ACTION_UNSUBSCRIBE = 'unsubscribe';
    ACTION_PUBLISH = 'publish';
    ACTION_SEND_FILE = 'send_file';
    ACTION_START_FILE_TRANSFER = 'start_file_transfer';
    ACTION_FILE_CHUNK = 'file_chunk';
    ACTION_REQUEST_FILE = 'request_file';
    ACTION_AUTHENTICATE = 'authenticate';

    /**
     * Constructs an instance of the class with the specified parameters.
     *
     * @param {string} url - The URL for the WebSocket connection.
     * @param options
     * @return {void}
     */
    constructor(url, options = {}) {
        /**
         * Represents a token that can be used for authentication or authorization purposes.
         * The value is initialized as null, indicating that no token is currently set.
         * The token may be updated during runtime with a valid authentication token.
         * @param {number} maxFileSize - The maximum allowed file size in bytes for transfers.
         * @param {number} [maxReconnectAttempts=30] - The maximum number of reconnect attempts in case of connection failure.
         * @param {boolean} [verbose=false] - Enables verbose logging when set to true.
         */
        const {
            token = null,
            authTimeout = 5000,
            maxFileSize = 100 * 1024 * 1024,
            maxReconnectAttempts = 30,
            verbose = false,
            allowGuest = true
            // ... otras opciones=
        } = options;

        super();
        this.url = url;
        this.token = token;
        this.authTimeout = authTimeout;
        this.isAuthenticated = false;
        this.allowGuest = allowGuest;
        this.ws = null;
        this.subscriptions = new Set();
        this.rpcHandlers = new Map();
        this.fileHandlers = new Map();
        this.fileTransfers = new Map();
        this.channelCallbacks = new Map(); // Nuevo: callbacks por canal
        this.pendingCallbacks = new Map(); // Nuevo: callbacks pendientes de reconexión
        this.chunkSize = 64 * 1024; // 64KB chunks
        this.maxFileSize = maxFileSize ?? 100 * 1024 * 1024;
        this.maxReconnectAttempts = maxReconnectAttempts;
        this.reconnectAttempts = 0;
        this.reconnectDelay = 1000;
        this.toResubscribe = new Set();
        this.isManualClose = false;

        if (verbose) {
            this.log = console.log.bind(console);
            this.error = console.error.bind(console);
            this.debug = console.debug.bind(console);
            this.info = console.info.bind(console);
        } else {
            this.log = this.error = this.debug = this.info = () => {
            };
        }
    }

    /**
     * Suscribe a un canal para recibir mensajes con callback opcional.
     * @param {string} channel - El canal al que suscribirse
     * @param {Function} [callback] - Función callback para mensajes de este canal
     * @param {Object} [scope] - Scope para ejecutar el callback (this)
     * @return {void}
     */
    subscribe(channel, callback, scope) {
        if (!this.isReady()) {
            if (!this.isAuthenticated) {
                this.debug('Not authenticated, saving subscription');
            } else {
                this.debug('WebSocket is not ready, saving your subscription to try again later');
            }
            if (!this.toResubscribe.has(channel)) {
                this.toResubscribe.add(channel);
            }

            // Guardar callback pendiente si se proporcionó
            if (callback) {
                this.pendingCallbacks.set(channel, {callback, scope});
            }

            return;
        }

        if (this.subscriptions.has(channel)) {
            this.log('Channel already registered: ', channel);
            // Aun así registramos el callback si se proporciona
            if (callback) {
                this.addChannelCallback(channel, callback, scope);
            }
            return;
        }

        this.ws.send(JSON.stringify({
            action: this.ACTION_SUBSCRIBE,
            channel: channel
        }));

        this.subscriptions.add(channel);
        this.log('Subscribed to the channel:', channel);

        // Registrar callback si se proporciona
        if (callback) {
            this.addChannelCallback(channel, callback, scope);
        }

        this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_SUBSCRIBED, {detail: channel}));
    }

    /**
     * Agrega un callback para un canal específico.
     * @private
     * @param {string} channel - El canal
     * @param {Function} callback - Función callback
     * @param {Object} scope - Scope para el callback
     */
    addChannelCallback(channel, callback, scope) {
        let callbacks = this.channelCallbacks.get(channel) || [];
        callbacks.push({
            fn: callback,
            scope: scope || this
        });
        this.channelCallbacks.set(channel, callbacks);
    }

    /**
     * Desuscribe de un canal, opcionalmente solo un callback específico.
     * @param {string} channel - El canal
     * @param {Function} [callback] - Callback específico a remover (si no se proporciona, desuscribe completamente)
     * @return {void}
     */
    unsubscribe(channel, callback) {
        if (!this.isReady()) {
            return;
        }

        // Si no se especifica callback, desuscribir completamente
        if (!callback) {
            this.ws.send(JSON.stringify({
                action: this.ACTION_UNSUBSCRIBE,
                channel: channel
            }));

            this.subscriptions.delete(channel);
            this.toResubscribe.delete(channel);
            this.channelCallbacks.delete(channel);
            this.pendingCallbacks.delete(channel);

            this.log('Unsubscribed from the channel:', channel);
            this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_UNSUBSCRIBED, {detail: channel}));
        } else {
            // Solo remover callback específico
            const callbacks = this.channelCallbacks.get(channel);
            if (callbacks) {
                const newCallbacks = callbacks.filter(cb => cb.fn !== callback);

                if (newCallbacks.length === 0) {
                    // Si no quedan callbacks, desuscribir del servidor
                    this.unsubscribe(channel);
                } else {
                    this.channelCallbacks.set(channel, newCallbacks);
                    this.log('Callback removed from channel:', channel);
                }
            }
        }
    }

    /**
     * Establece la conexión WebSocket.
     * @return {void}
     */
    connect() {
        if (this.isManualClose) {
            this.debug('Manually closed connection, do not reconnect');
            return;
        }

        try {
            this.ws = new WebSocket(this.url);
            this.isAuthenticated = false;
            this.setupEventListeners();
        } catch (error) {
            this.error('Error creating WebSocket:', error);
            this.scheduleReconnect();
        }
    }

    /**
     * Enviar token para autenticación
     */
    authenticate() {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            return;
        }

        // Timeout para autenticación
        this.authTimeoutId = setTimeout(() => {
            if (!this.isAuthenticated) {
                this.error('Authentication timeout');
                this.ws.close(4001, 'Authentication timeout');
            }
        }, this.authTimeout);

        // Enviar token
        this.ws.send(JSON.stringify({
            action: this.ACTION_AUTHENTICATE,
            token: this.token
        }));
    }

    /**
     * Actualizar token y reautenticar
     */
    updateToken(newToken, reauthenticate = true) {
        this.token = newToken;
        if (reauthenticate && this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.isAuthenticated = false;
            this.authenticate();
        }
    }

    /**
     * Manejar respuesta de autenticación
     */
    handleAuthResponse(data) {
        clearTimeout(this.authTimeoutId);
        if (data.success) {
            this.onAuthenticated();
        } else {
            this.error('Authentication failed:', data.error);
            this.dispatchEvent(new CustomEvent('auth_error', {
                detail: {error: data.error}
            }));
            this.ws.close(4000, 'Authentication failed');
        }
    }

    /**
     * Llamado cuando la autenticación es exitosa
     */
    onAuthenticated() {
        this.isAuthenticated = true;
        this.log('Authenticated with Pub/Sub server');
        this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_READY, {
            detail: {subscriptions: this.subscriptions}
        }));

        this.reconnectAttempts = 0;
        this.reconnectDelay = 1000;

        if (this.toResubscribe.size > 0) {
            this.toResubscribe.forEach(channel => this.subscribe(channel));
            this.toResubscribe.clear();
        }

        this.restorePendingCallbacks();
    }

    /**
     * Configura los listeners de eventos del WebSocket.
     * @private
     * @return {void}
     */
    setupEventListeners() {
        this.ws.onopen = () => {

            this.log('WebSocket connected, authenticating...');

            // Sí hay token, autenticar siempre. Si no se permite guests autenticar aunque no haya token. De esa forma se informa el error de autenticación
            if (this.token || !this.allowGuest) {
                this.authenticate();
            } else {
                this.onAuthenticated();
            }

            this.log('Connected to the Pub/Sub server');

            this.reconnectAttempts = 0;
            this.reconnectDelay = 1000;

            if (this.toResubscribe.size > 0) {
                // Resuscribir a canales anteriores
                this.toResubscribe.forEach(channel => this.subscribe(channel));
                this.toResubscribe.clear();
            }

            // Restaurar callbacks pendientes
            this.restorePendingCallbacks();
        };

        this.ws.onmessage = (event) => {
            this.debug('Message received:', event);
            try {
                const data = JSON.parse(event.data);
                // Verificar si es respuesta de autenticación
                if (data.type === 'auth_response') {
                    this.handleAuthResponse(data);
                    return;
                }

                // Solo procesar otros mensajes si está autenticado
                if (this.isAuthenticated) {
                    this.handleMessage(data);
                }
            } catch (error) {
                this.error('Error parsing message:', error);
                this.dispatchEvent(new ErrorEvent(this.EVENT_TYPE_ERROR, {
                    message: 'Error parsing message',
                    error: error
                }));
            }
        };

        this.ws.onclose = (event) => {
            this.info(`Connection closed. Code: ${event.code}, Reason: ${event.reason}`);

            if (!this.isManualClose && !event.wasClean &&
                this.reconnectAttempts < this.maxReconnectAttempts) {

                // Guardar suscripciones y callbacks actuales para reconexión
                this.subscriptions.forEach(channel => {
                    if (!this.toResubscribe.has(channel)) {
                        this.toResubscribe.add(channel);
                    }

                    // Guardar callbacks asociados a este canal
                    const callbacks = this.channelCallbacks.get(channel);
                    if (callbacks) {
                        // Solo guardamos el primer callback para simplificar
                        // En producción podrías querer guardarlos todos
                        if (callbacks.length > 0 && !this.pendingCallbacks.has(channel)) {
                            this.pendingCallbacks.set(channel, {
                                callback: callbacks[0].fn,
                                scope: callbacks[0].scope
                            });
                        }
                    }
                });

                this.scheduleReconnect();
            } else {
                if (!this.isManualClose && !event.wasClean &&
                    this.reconnectAttempts === this.maxReconnectAttempts) {

                    this.error('Unable to connect to Pub/Sub server, connection attempts exceeded.');
                    this.dispatchEvent(new ErrorEvent(this.EVENT_TYPE_ERROR, {
                        message: 'The maximum number of attempts to connect to the Pub/Sub server has been reached.'
                    }));
                } else {
                    this.debug('Connection closed cleanly', event);
                    this.dispatchEvent(new CloseEvent(this.EVENT_TYPE_CLOSE, {
                        type: this.EVENT_TYPE_CLOSE,
                        wasClean: event.wasClean,
                        code: event.code,
                        reason: event.reason
                    }));
                    this.isManualClose = false;
                }
            }

            this.ws = null;
            this.subscriptions.clear();
            this.channelCallbacks.clear();
        };

        this.ws.onerror = (error) => {
            this.error('WebSocket Error:', error);
            this.dispatchEvent(new ErrorEvent(this.EVENT_TYPE_ERROR, {
                message: 'WebSocket Error. Status: ' + this.getStatus(),
                error: error
            }));
        };
    }

    /**
     * Restaura callbacks pendientes después de reconectar.
     * @private
     * @return {void}
     */
    restorePendingCallbacks() {
        this.pendingCallbacks.forEach((callbackObj, channel) => {
            this.addChannelCallback(channel, callbackObj.callback, callbackObj.scope);
        });
        this.pendingCallbacks.clear();
    }

    /**
     * Maneja los mensajes entrantes y ejecuta los callbacks correspondientes.
     * @private
     * @param {Object} data - Los datos del mensaje
     * @return {void}
     */
    handleMessage(data) {
        switch (data.type) {
            case this.MESSAGE_TYPE_MESSAGE:
                // 1. Disparar evento general (compatibilidad)
                this.dispatchEvent(new MessageEvent(this.EVENT_TYPE_MESSAGE, {data}));
                // 2. Ejecutar callbacks específicos del canal
                if (data.channel) {
                    this.executeChannelCallbacks(data.channel, data.data);
                }
                break;

            case this.MESSAGE_TYPE_FILE_RESPONSE:
                this.handleFileResponse(data);
                break;

            case this.MESSAGE_TYPE_FILE_AVAILABLE:
                this.dispatchEvent(new CustomEvent('file_available', {
                    detail: data
                }));
                break;

            case this.MESSAGE_TYPE_RPC_RESPONSE:
                this.debug('RPC response received:', data);
                this.handleRpcResponse(data);
                break;

            case this.MESSAGE_TYPE_RPC_METHODS:
                this.dispatchEvent(new CustomEvent('rpc_methods', {detail: data.methods}));
                break;

            case this.MESSAGE_TYPE_SUCCESS:
                this.log('Process completed successfully: ', data.message);
                this.dispatchEvent(new CustomEvent('success', {detail: data}));
                break;

            case this.MESSAGE_TYPE_ERROR:
                this.error('Error:', data.message);
                this.dispatchEvent(new ErrorEvent('error', {
                    message: data.message,
                    error: data.error
                }));
                break;
        }
    }

    /**
     * Ejecuta todos los callbacks registrados para un canal.
     * @private
     * @param {string} channel - El canal
     * @param {any} data - Los datos del mensaje
     * @return {void}
     */
    executeChannelCallbacks(channel, data) {
        const callbacks = this.channelCallbacks.get(channel);
        if (callbacks) {
            callbacks.forEach(callbackObj => {
                try {
                    callbackObj.fn.call(callbackObj.scope, data);
                } catch (error) {
                    this.error(`Error in channel callback for ${channel}:`, error);
                }
            });
        }
    }

    /**
     * Publica un mensaje en un canal.
     * @param {string} channel - El canal
     * @param {any} data - Los datos a publicar
     * @return {void}
     */
    publish(channel, data) {
        if (!this.isReady()) {
            this.dispatchEvent(new ErrorEvent(this.EVENT_TYPE_ERROR, {
                message: 'WebSocket is not connected, please reconnect and try again',
            }));
            return;
        }

        this.ws.send(JSON.stringify({
            action: this.ACTION_PUBLISH,
            channel: channel,
            data: data
        }));

        this.debug('Published message on channel:', channel);
        this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_PUBLISHED, {
            detail: {channel: channel, data: data}
        }));
    }

    /**
     * Retorna un objeto para interactuar con un canal específico (fluent API).
     * @param {string} channel - El canal
     * @param {Object} [scope] - Scope para los callbacks
     * @return {Object} Interfaz fluida para el canal
     */
    channel(channel, scope) {
        const me = this;
        const actualScope = scope || this;

        return {
            /**
             * Suscribe al canal con un callback.
             * @param {Function} callback - El callback
             * @return {Object} this para chaining
             */
            subscribe(callback) {
                me.subscribe(channel, callback, actualScope);
                return this;
            },

            /**
             * Desuscribe del canal.
             * @param {Function} [callback] - Callback específico a remover
             * @return {Object} this para chaining
             */
            unsubscribe(callback) {
                me.unsubscribe(channel, callback);
                return this;
            },

            /**
             * Publica en el canal.
             * @param {any} data - Los datos a publicar
             * @return {void}
             */
            publish(data) {
                me.publish(channel, data);
            },

            /**
             * Verifica si está suscrito al canal.
             * @return {boolean}
             */
            isSubscribed() {
                return me.subscriptions.has(channel);
            }
        };
    }

    /**
     * Métodos restantes (sin cambios significativos)
     */

    scheduleReconnect() {
        const delay = Math.min(
            this.reconnectDelay * Math.pow(2, this.reconnectAttempts),
            30000
        );
        const logMsg = `Reconnecting in ${delay} ms (Attempt ${this.reconnectAttempts + 1}/${this.maxReconnectAttempts})`;

        this.log(logMsg);
        this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_RECONNECTING, {
            detail: logMsg,
            reconnectAttempts: this.reconnectAttempts + 1,
            maxReconnectAttempts: this.maxReconnectAttempts,
            delay: delay
        }));

        setTimeout(() => {
            this.reconnectAttempts++;
            this.connect();
        }, delay);
    }

    disconnect() {
        this.isManualClose = true;
        if (this.ws) {
            this.subscriptions.forEach(channel => this.unsubscribe(channel));
            setTimeout(() => {
                this.ws.close(1000, 'Manual disconnect');
                this.ws = null;
            }, 500);

            this.subscriptions.clear();
            this.channelCallbacks.clear();
            this.pendingCallbacks.clear();
            this.reconnectAttempts = 0;
            this.reconnectDelay = 1000;
            this.toResubscribe.clear();

            this.debug('Disconnected from the Pub/Sub server');
        }
    }

    async sendFile(channel, file, metadata = {}, timeout = 30000) {
        if (!this.isReady()) {
            throw new Error('WebSocket is not connected');
        }

        if (file.size > this.maxFileSize) {
            throw new Error('The file exceeds the limit of ' +
                this.maxFileSize / 1024 / 1024 + ' MB.');
        }

        if (file.size > 25 * 1024 * 1024) {
            return this.sendLargeFile(channel, file, metadata);
        }

        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            const requestId = this.generateRequestId();
            let timeoutId;

            if (timeout > 0) {
                timeoutId = setTimeout(() => {
                    this.fileHandlers.delete(requestId);
                    reject(new Error('Timeout sending file'));
                }, timeout);
            }

            this.fileHandlers.set(requestId, {
                resolve: (data) => {
                    clearTimeout(timeoutId);
                    resolve(data);
                },
                reject: (error) => {
                    clearTimeout(timeoutId);
                    reject(error);
                }
            });

            reader.onload = () => {
                const base64Data = reader.result.split(',')[1];
                this.ws.send(JSON.stringify({
                    action: this.ACTION_SEND_FILE,
                    channel: channel,
                    request_id: requestId,
                    file: {
                        name: file.name,
                        type: file.type,
                        size: file.size,
                        data: base64Data
                    },
                    metadata: metadata
                }));
            };

            reader.onerror = () => reject(new Error('Error reading file'));
            reader.readAsDataURL(file);
        });
    }

    async sendLargeFile(channel, file, metadata = {}) {
        if (!this.isReady()) {
            throw new Error('WebSocket is not connected');
        }

        const fileId = this.generateRequestId();
        const totalChunks = Math.ceil(file.size / this.chunkSize);
        let currentChunk = 0;

        return new Promise((resolve, reject) => {
            this.fileTransfers.set(fileId, {
                file: file,
                totalChunks: totalChunks,
                receivedChunks: 0,
                chunks: new Array(totalChunks),
                resolve,
                reject
            });

            this.ws.send(JSON.stringify({
                action: this.ACTION_START_FILE_TRANSFER,
                channel: channel,
                file_id: fileId,
                file_name: file.name,
                file_type: file.type,
                file_size: file.size,
                total_chunks: totalChunks,
                metadata: metadata
            }));

            this.sendNextChunk(fileId, currentChunk);
        });
    }

    sendNextChunk(fileId, chunkIndex) {
        const transfer = this.fileTransfers.get(fileId);
        if (!transfer || chunkIndex >= transfer.totalChunks) {
            return;
        }

        const start = chunkIndex * this.chunkSize;
        const end = Math.min(start + this.chunkSize, transfer.file.size);
        const chunk = transfer.file.slice(start, end);

        const reader = new FileReader();
        reader.onload = () => {
            const base64Data = reader.result.split(',')[1];
            this.ws.send(JSON.stringify({
                action: this.ACTION_FILE_CHUNK,
                file_id: fileId,
                chunk_index: chunkIndex,
                chunk_data: base64Data,
                is_last: chunkIndex === transfer.totalChunks - 1
            }));

            if (chunkIndex < transfer.totalChunks - 1) {
                setTimeout(() => {
                    this.sendNextChunk(fileId, chunkIndex + 1);
                }, 0);
            }
        };

        reader.readAsDataURL(chunk);
    }

    requestFile(fileId, fileName = 'download') {
        if (!this.isReady()) {
            throw new Error('WebSocket is not connected');
        }

        const requestId = this.generateRequestId();
        return new Promise((resolve, reject) => {
            this.fileHandlers.set(requestId, {resolve, reject});
            this.ws.send(JSON.stringify({
                action: this.ACTION_REQUEST_FILE,
                request_id: requestId,
                file_id: fileId,
                file_name: fileName
            }));
        });
    }

    handleFileResponse(data) {
        const handler = this.fileHandlers.get(data.request_id);
        if (handler) {
            this.fileHandlers.delete(data.request_id);

            if (data.success) {
                const binaryData = atob(data.file.data);
                const bytes = new Uint8Array(binaryData.length);
                for (let i = 0; i < binaryData.length; i++) {
                    bytes[i] = binaryData.charCodeAt(i);
                }

                const blob = new Blob([bytes], {type: data.file.type});
                const url = URL.createObjectURL(blob);

                handler.resolve({
                    blob: blob,
                    url: url,
                    name: data.file.name,
                    type: data.file.type,
                    size: data.file.size
                });
            } else {
                handler.reject(new Error(data.error || 'Unknown error'));
            }
        }
    }

    handleRpcResponse(data) {
        const handler = this.rpcHandlers.get(data.id);

        if (!handler) {
            this.debug('No handler for RPC ID:', data.id);
            return;
        }

        switch (data.status) {
            case this.RPC_STATUS_ACCEPTED:
                this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_RPC_ACCEPTED, {
                    detail: {
                        id: data.id,
                        worker_id: data.worker_id,
                        method: handler.method,
                        timestamp: data.timestamp
                    }
                }));
                this.debug(`RPC ${data.id} (${handler.method}) accepted by worker ${data.worker_id}`);
                break;

            case this.RPC_STATUS_COMPLETED:
                this.debug(`RPC ${data.id} (${handler.method}) completed in ${Date.now() - handler.startTime}ms`);
                handler.resolve(data.result);
                this.rpcHandlers.delete(data.id);
                this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_RPC_COMPLETED, {
                    detail: {
                        id: data.id,
                        method: handler.method,
                        result: data.result,
                        execution_time: data.execution_time,
                        timestamp: data.timestamp
                    }
                }));
                break;

            case this.RPC_STATUS_ERROR:
            case this.RPC_STATUS_REJECTED:
                this.error(`RPC ${data.id} (${handler.method}) failed:`, data.error?.message);
                handler.reject(new Error(data.error?.message || 'Unknown RPC error'));
                this.rpcHandlers.delete(data.id);
                this.dispatchEvent(new ErrorEvent(
                    this.EVENT_TYPE_ERROR,
                    {message: `RPC ${data.id} (${handler.method}) failed: ${data.error?.message || 'Unknown RPC error'}`},
                ));
                this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_RPC_ABORTED, {
                    detail: {
                        id: data.id,
                        method: handler.method,
                        error: data.error?.message || 'Unknown RPC error',
                        timestamp: data.timestamp
                    }
                }));
                break;

            default:
                this.warn(`Unknown RPC status for ${data.id}:`, data.status);
        }
    }

    async rpc(method, params = {}, timeout = 30000) {
        if (!this.isReady()) {
            throw new Error('WebSocket not connected. Cannot execute RPC.');
        }

        return new Promise((resolve, reject) => {
            const requestId = this.generateRequestId();
            let timeoutId;

            this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_RPC_START_EXEC, {
                detail: {id: requestId, method: method}
            }));

            const cleanup = () => {
                clearTimeout(timeoutId);
                this.rpcHandlers.delete(requestId);
            };

            if (timeout > 0) {
                timeoutId = setTimeout(() => {
                    cleanup();
                    reject(new Error(`RPC timeout(${timeout}ms) on method: ${method}`));
                }, timeout);
            }

            this.rpcHandlers.set(requestId, {
                resolve: (data) => {
                    cleanup();
                    resolve(data);
                },
                reject: (error) => {
                    cleanup();
                    reject(error);
                },
                method: method,
                startTime: Date.now()
            });

            this.ws.send(JSON.stringify({
                action: this.ACTION_RPC_CALL,
                id: requestId,
                method: method,
                params: params,
                timeout: Math.floor(timeout / 1000)
            }));

            this.debug(`RPC ${requestId} sent: ${method}`);
        });
    }

    listRpcMethods() {
        if (!this.isReady()) {
            return Promise.reject(new Error('WebSocket disconnected. Cannot list RPC methods.'));
        }

        return new Promise((resolve) => {
            const handler = (event) => {
                this.removeEventListener(this.EVENT_TYPE_RPC_METHODS, handler);
                resolve(event.detail);
            };

            this.addEventListener(this.EVENT_TYPE_RPC_METHODS, handler);
            this.ws.send(JSON.stringify({action: this.ACTION_LIST_RPC_METHODS}));
        });
    }

    generateRequestId() {
        return Date.now().toString(36) + Math.random().toString(36).substr(2);
    }

    downloadFile(blobData, fileName) {
        const link = document.createElement('a');
        link.href = blobData.url;
        link.download = fileName || blobData.name;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(() => URL.revokeObjectURL(blobData.url), 1000);
    }

    /**
     * Registra un event listener (alias para addEventListener).
     * @param {string} eventName - El nombre del evento
     * @param {Function} callback - El callback
     * @return {void}
     */
    on(eventName, callback) {
        this.addEventListener(eventName, callback);
    }

    /**
     * Remueve un event listener (alias para removeEventListener).
     * @param {string} eventName - El nombre del evento
     * @param {Function} callback - El callback
     * @return {void}
     */
    un(eventName, callback) {
        this.removeEventListener(eventName, callback);
    }

    isReady() {
        return this.ws && this.ws.readyState === WebSocket.OPEN && this.isAuthenticated;
    }

    getStatus() {
        if (!this.ws) return 'disconnected';
        switch (this.ws.readyState) {
            case WebSocket.CONNECTING:
                return 'connecting';
            case WebSocket.OPEN:
                return 'connected';
            case WebSocket.CLOSING:
                return 'closing';
            case WebSocket.CLOSED:
                return 'closed';
            default:
                return 'unknown';
        }
    }

    /**
     * Retorna los canales a los que está suscrito.
     * @return {Array<string>}
     */
    getSubscribedChannels() {
        return Array.from(this.subscriptions);
    }

    /**
     * Verifica si está suscrito a un canal específico.
     * @param {string} channel - El canal
     * @return {boolean}
     */
    hasSubscription(channel) {
        return this.subscriptions.has(channel);
    }
}