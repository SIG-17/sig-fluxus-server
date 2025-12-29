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
    MESSAGE_TYPE_RPC_SUCCESS = 'rpc_success';
    MESSAGE_TYPE_RPC_ERROR = 'rpc_error';
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
            allowGuest = true,
            loadRpcMethodsOnConnect = true,
            rpcMethodsUrl = null, // URL para cargar métodos (ej: '/api/rpc-methods')
            autoGenerateRpcStubs = true,
            rpcBaseNamespace = null
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

        this.loadRpcMethodsOnConnect = loadRpcMethodsOnConnect;
        this.rpcMethodsUrl = rpcMethodsUrl;
        this.autoGenerateRpcStubs = autoGenerateRpcStubs;
        this.rpcMethodsMetadata = new Map(); // Almacena metadatos de métodos
        this.rpcStubsGenerated = false;
        this.rpcBaseNamespace = rpcBaseNamespace || this;

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

        // Cargar métodos RPC si está configurado
        if (this.loadRpcMethodsOnConnect && this.rpcMethodsUrl) {
            this.loadRpcMethodsFromUrl().then(methods => {
                this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_READY, {
                    detail: {subscriptions: this.subscriptions, rpcMethods: methods}
                }));
            }).catch(error => {
                this.warn('Failed to load RPC methods:', error);
            });
        } else {

            this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_READY, {
                detail: {subscriptions: this.subscriptions}
            }));
        }


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
     * Carga métodos RPC desde un endpoint HTTP
     */
    async loadRpcMethodsFromUrl(url = null) {
        const targetUrl = url || this.rpcMethodsUrl;

        if (!targetUrl) {
            throw new Error('No RPC methods URL specified');
        }

        try {
            this.debug('Loading RPC methods from:', targetUrl);

            const response = await fetch(targetUrl);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (data.type !== 'rpc' || !Array.isArray(data.methods)) {
                throw new Error('Invalid RPC methods response');
            }

            // Almacenar metadatos
            this.rpcMethodsMetadata.clear();
            data.methods.forEach(method => {
                this.rpcMethodsMetadata.set(method.method, method);
            });

            this.debug(`Loaded ${data.methods.length} RPC methods`);

            // Generar stubs automáticamente si está configurado
            if (this.autoGenerateRpcStubs) {
                this.generateRpcStubs();
            }

            // Disparar evento
            this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_RPC_METHODS, {
                detail: data.methods
            }));

            return data.methods;

        } catch (error) {
            this.error('Error loading RPC methods:', error);
            throw error;
        }
    }

    /**
     * Genera funciones stub para todos los métodos RPC cargados
     */
    generateRpcStubs() {
        if (this.rpcStubsGenerated) {
            this.debug('RPC stubs already generated');
            return;
        }

        for (const [methodName, methodInfo] of this.rpcMethodsMetadata) {
            // Crear función para este método
            this[methodName] = this.createRpcStubFunction(methodName, methodInfo);

            // También crear versión con namespaces (ej: "db.health.status")
            if (methodName.includes('.')) {
                this.createNamespacedRpcStub(methodName, methodInfo);
            }
        }

        this.rpcStubsGenerated = true;
        this.debug(`Generated RPC stubs for ${this.rpcMethodsMetadata.size} methods`);
    }

    /**
     * Crea una función stub individual para un método RPC
     */
    createRpcStubFunction(methodName, methodInfo) {
        return function (...args) {
            let params = {};

            // Si es un solo argumento y es objeto, usarlo directamente
            if (args.length === 1 && typeof args[0] === 'object' && args[0] !== null) {
                params = args[0];
            }
            // Si hay múltiples argumentos y conocemos los nombres de parámetros
            else if (methodInfo.params && methodInfo.params.length > 0) {
                // Crear mapeo de parámetros por nombre
                const paramMap = {};
                methodInfo.params.forEach(param => {
                    paramMap[param.name] = param;
                });

                const paramNames = methodInfo.params.map(p => p.name);
                paramNames.forEach((paramName, index) => {
                    if (index < args.length) {
                        params[paramName] = args[index];
                    } else {
                        // Usar valor por defecto si existe
                        const paramDef = paramMap[paramName];
                        if (paramDef && paramDef.default !== null) {
                            params[paramName] = paramDef.default;
                        }
                    }
                });
            }
            // Fallback: pasar todos los args como array
            else if (args.length > 0) {
                params = {args: args};
            }

            // Validar parámetros requeridos
            const missingParams = this.validateRpcParameters(methodInfo, params);
            if (missingParams.length > 0) {
                return Promise.reject(new Error(
                    `Missing required parameters for ${methodName}: ${missingParams.join(', ')}`
                ));
            }

            // Llamar al método RPC
            return this.rpc(methodName, params);
        }.bind(this);
    }

    /**
     * Crea stubs namespaced (ej: client.db.health.status())
     */
    createNamespacedRpcStub(fullName, methodInfo) {
        const parts = fullName.split('.');
        let current = this.rpcBaseNamespace;
        if (typeof current !== 'object') {
            if (current === null) {
                current = this;
            }
            if (typeof current === 'string') {
                if (!window[current]) {
                    window[current] = {};
                }
                current = window[current];
            }
        }

        // Crear namespaces anidados
        for (let i = 0; i < parts.length - 1; i++) {
            const part = parts[i];
            if (!current[part] || typeof current[part] !== 'object') {
                current[part] = {};
            }
            current = current[part];
        }

        // Asignar el método al último namespace
        const methodName = parts[parts.length - 1];
        current[methodName] = this.createRpcStubFunction(fullName, methodInfo);
    }

    /**
     * Valida los parámetros de un método RPC
     */
    validateRpcParameters(methodInfo, params) {
        const missing = [];

        if (Array.isArray(methodInfo.params)) {
            methodInfo.params.forEach(param => {
                if (param.required &&
                    (params[param.name] === undefined || params[param.name] === null)) {
                    missing.push(param.name);
                }
            });
        }

        return missing;
    }

    /**
     * Obtiene información de un método RPC específico
     */
    getRpcMethodInfo(methodName) {
        //console.log(this.rpcMethodsMetadata, methodName);
        return this.rpcMethodsMetadata.get(methodName);
    }

    /**
     * Lista todos los métodos RPC disponibles
     */
    listRpcMethods() {
        return Array.from(this.rpcMethodsMetadata.values());
    }

    /**
     * Busca métodos RPC por nombre o descripción
     */
    searchRpcMethods(query) {
        query = query.toLowerCase();
        return Array.from(this.rpcMethodsMetadata.values()).filter(method => {
            return method.method.toLowerCase().includes(query) ||
                method.description.toLowerCase().includes(query);
        });
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
            case this.MESSAGE_TYPE_RPC_ERROR:
            case this.MESSAGE_TYPE_RPC_RESPONSE:
            case this.MESSAGE_TYPE_RPC_SUCCESS:
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
                // Marcar como aceptado
                if (handler.accept) {
                    handler.accept();
                }
                // Actualizar estado del handler
                handler.accepted = true;
                handler.acceptedAt = Date.now();
                this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_RPC_ACCEPTED, {
                    detail: {
                        id: data.id,
                        worker_id: data._metadata?.worker_id,
                        method: handler.method,
                        timestamp: data._metadata?.timestamp
                    }
                }));
                this.debug(`RPC ${data.id} (${handler.method}) accepted by worker ${data.worker_id}`);
                if (!handler.status) {
                    handler.status = 'accepted';
                    handler.acceptedAt = Date.now();
                }
                break;

            case this.RPC_STATUS_COMPLETED:
                this.debug(`RPC ${data.id} (${handler.method}) completed in ${Date.now() - handler.startTime}ms`);
                // Verificar que fue aceptado primero
                if (!handler.accepted) {
                    this.warn(`RPC ${data.id} completed without being accepted first`);
                }
                if (handler.resolve) {
                    handler.resolve(data.result || data.message || data);
                }
                this.rpcHandlers.delete(data.id);
                this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_RPC_COMPLETED, {
                    detail: {
                        id: data.id,
                        method: handler.method,
                        result: data.result || data.message,
                        execution_time: data._metadata?.execution_time,
                        timestamp: data._metadata?.timestamp
                    }
                }));
                break;

            case this.RPC_STATUS_ERROR:
            case this.RPC_STATUS_REJECTED:
                console.trace(data);
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
                        timestamp: data._metadata?.timestamp
                    }
                }));
                break;

            default:
                this.warn(`Unknown RPC status for ${data.id}:`, data.status);
        }
    }

    /**
     * Método RPC existente - mejorado para trabajar con stubs
     */// Versión corregida de rpc() - SIN async
    rpc(method, params = {}, timeout = 30000) {
        if (!this.isReady()) {
            return Promise.reject(new Error('WebSocket not connected. Cannot execute RPC.'));
        }

        const methodInfo = this.getRpcMethodInfo(method);
        if (methodInfo && !methodInfo.allow_guest && !this.token) {
            return Promise.reject(new Error(`Method ${method} requires authentication`));
        }

        return new Promise((resolve, reject) => {
            const requestId = this.generateRequestId();
            let timeoutId;
            let accepted = false;

            this.dispatchEvent(new CustomEvent(this.EVENT_TYPE_RPC_START_EXEC, {
                detail: {id: requestId, method: method, params: params}
            }));

            const cleanup = () => {
                if (timeoutId) {
                    clearTimeout(timeoutId);
                    timeoutId = null;
                }
                this.rpcHandlers.delete(requestId);
            };

            if (timeout > 0) {
                // Primer timeout: si no es aceptado rápidamente
                const acceptTimeout = Math.min(5000, timeout); // 5 segundos o menos
                timeoutId = setTimeout(() => {
                    if (!accepted) {
                        this.error(`RPC ${requestId} not accepted within ${acceptTimeout}ms`);
                        cleanup();
                        reject(new Error(`RPC not accepted within ${acceptTimeout}ms for method: ${method}`));
                    } else {
                        // Si fue aceptado pero no completado, usar timeout completo
                        this.log(`RPC ${requestId} accepted, waiting for completion (${timeout - acceptTimeout}ms remaining)`);

                        // Configurar nuevo timeout para la finalización
                        timeoutId = setTimeout(() => {
                            this.error(`RPC ${requestId} timeout after acceptance: ${method}`);
                            cleanup();
                            reject(new Error(`RPC timeout(${timeout}ms) on method: ${method} (was accepted but not completed)`));
                        }, timeout - acceptTimeout);
                    }
                }, acceptTimeout);
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
                accept: () => {
                    console.log(`📥 RPC ${requestId} marked as accepted`);
                    accepted = true;
                    // Podemos extender el timeout aquí si queremos
                },
                method: method,
                params: params,
                startTime: Date.now(),
                accepted: false
            });

            try {
                this.ws.send(JSON.stringify({
                    action: this.ACTION_RPC_CALL,
                    id: requestId,
                    method: method,
                    params: params,
                    timeout: Math.floor(timeout / 1000)
                }));

                this.debug(`RPC ${requestId} sent: ${method}`, params);
            } catch (error) {
                cleanup();
                reject(new Error(`Failed to send RPC: ${error.message}`));
            }
        });
    }

    /* listRpcMethods() {
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
     }*/

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

    /**
     * Genera documentación de métodos RPC en formato HTML
     * Ahora incluye el script para testRpcMethod
     */
    generateRpcDocumentation() {
        const methods = this.listRpcMethods();

        // Asegurar que la función global existe
        if (!window.testRpcMethod) {
            this.createGlobalTestRpcMethod();
        }

        const html = `
        <div class="rpc-documentation">
            <div style="margin-bottom: 20px; padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; color: white;">
                <h1 style="margin: 0 0 10px 0; font-size: 24px;">📚 RPC Methods Documentation</h1>
                <div style="display: flex; gap: 20px; font-size: 14px;">
                    <div>
                        <strong>Total methods:</strong> ${methods.length}
                    </div>
                    <div>
                        <strong>Server:</strong> ${this.url}
                    </div>
                    <div>
                        <strong>Status:</strong> 
                        <span style="display: inline-block; padding: 2px 8px; background: ${this.isReady() ? 'rgba(255,255,255,0.2)' : 'rgba(220,53,69,0.8)'}; border-radius: 12px;">
                            ${this.isReady() ? '✅ Connected' : '❌ Disconnected'}
                        </span>
                    </div>
                </div>
            </div>
            
            <div style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center;">
                <input 
                    type="text" 
                    id="rpc-search" 
                    placeholder="🔍 Search methods by name or description..." 
                    style="flex: 1; padding: 12px; border: 1px solid #dee2e6; border-radius: 6px; font-size: 14px;"
                />
                <button id="clear-search" style="padding: 12px 16px; background: #6c757d; color: white; border: none; border-radius: 6px; cursor: pointer;">
                    Clear
                </button>
            </div>
            
            <div id="methods-count" style="margin-bottom: 10px; color: #666; font-size: 14px;">
                Showing ${methods.length} methods
            </div>
            
            <div id="rpc-methods-list">
                ${methods.map((method, index) => {
            // Determinar icono basado en el método
            let icon = '🔧';
            if (method.method.includes('db.')) icon = '🗄️';
            if (method.method.includes('auth.')) icon = '🔐';
            if (method.method.includes('server.')) icon = '🖥️';
            if (method.method.includes('ws.')) icon = '⚡';
            if (method.method.includes('ping')) icon = '🏓';
            if (method.method.includes('health')) icon = '❤️';

            return `
                        <div class="rpc-method" 
                             id="method-${method.method.replace(/\./g, '-')}" 
                             data-name="${method.method.toLowerCase()}" 
                             data-description="${method.description.toLowerCase()}"
                             data-index="${index}">
                            
                            <div class="rpc-method-header">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span style="font-size: 20px;">${icon}</span>
                                    <div>
                                        <h3 style="margin: 0; color: #0366d6; font-weight: 600; font-size: 16px;">
                                            ${method.method}
                                        </h3>
                                        <div class="method-metadata" style="display: flex; gap: 10px; font-size: 12px; color: #666; margin-top: 2px;">
                                            <span class="method-id">#${index + 1}</span>
                                            <span>•</span>
                                            <span>${method.params ? method.params.length : 0} params</span>
                                            ${method.allow_guest ?
                '<span style="color: #28a745;">• Guest allowed</span>' :
                '<span style="color: #dc3545;">• Auth required</span>'
            }
                                        </div>
                                    </div>
                                </div>
                                <button onclick="window.testRpcMethod('${method.method}')" class="test-button">
                                    🧪 Test
                                </button>
                            </div>
                            
                            <p class="description" style="margin: 12px 0; color: #495057; line-height: 1.5;">
                                ${method.description}
                            </p>
                            
                            ${method.params && method.params.length > 0 ? `
                                <div class="parameters-section">
                                    <h4 style="margin: 0 0 12px 0; color: #495057; font-size: 14px; font-weight: 600;">
                                        📋 Parameters
                                    </h4>
                                    <div class="parameters-grid">
                                        ${method.params.map(param => {
                // Determinar si tiene enum
                const hasEnum = param.enum && Array.isArray(param.enum) && param.enum.length > 0;

                return `
                                                <div class="parameter-card ${hasEnum ? 'has-enum' : ''}">
                                                    <div class="parameter-header">
                                                        <span class="param-name">${param.name}</span>
                                                        <span class="param-type ${param.required ? 'required' : 'optional'}">
                                                            ${param.type}
                                                            ${param.required ? ' (required)' : ' (optional)'}
                                                        </span>
                                                    </div>
                                                    
                                                    ${param.description ? `
                                                        <div class="param-description">
                                                            ${param.description}
                                                        </div>
                                                    ` : ''}
                                                    
                                                    ${hasEnum ? `
                                                        <div class="param-enum">
                                                            <div style="font-size: 12px; color: #6c757d; margin-bottom: 4px;">
                                                                Allowed values:
                                                            </div>
                                                            <div class="enum-values">
                                                                ${param.enum.map(value => `
                                                                    <span class="enum-value" title="Click to use this value">
                                                                        ${value}
                                                                    </span>
                                                                `).join('')}
                                                            </div>
                                                        </div>
                                                    ` : ''}
                                                    
                                                    <div class="param-details">
                                                        ${param.default !== null && param.default !== undefined ? `
                                                            <div class="param-detail">
                                                                <span class="detail-label">Default:</span>
                                                                <span class="detail-value">${typeof param.default === 'string' ? param.default : JSON.stringify(param.default)}</span>
                                                            </div>
                                                        ` : ''}
                                                        
                                                        ${param.example !== null && param.example !== undefined ? `
                                                            <div class="param-detail">
                                                                <span class="detail-label">Example:</span>
                                                                <span class="detail-value">${typeof param.example === 'string' ? param.example : JSON.stringify(param.example)}</span>
                                                            </div>
                                                        ` : ''}
                                                    </div>
                                                </div>
                                            `;
            }).join('')}
                                    </div>
                                </div>
                            ` : `
                                <div class="no-params">
                                    <span style="color: #6c757d; font-style: italic;">
                                        No parameters required
                                    </span>
                                </div>
                            `}
                        </div>
                    `;
        }).join('')}
            </div>
            
            ${methods.length === 0 ? `
                <div style="text-align: center; padding: 40px; color: #6c757d;">
                    <div style="font-size: 48px; margin-bottom: 20px;">📭</div>
                    <p style="margin: 0 0 20px 0; font-size: 16px;">No RPC methods available</p>
                    <button onclick="location.reload()" style="padding: 10px 20px; background: #0366d6; color: white; border: none; border-radius: 6px; cursor: pointer;">
                        🔄 Reload Methods
                    </button>
                </div>
            ` : ''}
        </div>
        
        <style>
            .rpc-documentation {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                color: #212529;
            }
            
            .rpc-method {
                background: white;
                border: 1px solid #e9ecef;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 16px;
                transition: all 0.2s ease;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }
            
            .rpc-method:hover {
                border-color: #0366d6;
                box-shadow: 0 2px 8px rgba(3, 102, 214, 0.1);
            }
            
            .rpc-method-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 8px;
            }
            
            .test-button {
                padding: 8px 16px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
                transition: transform 0.2s, box-shadow 0.2s;
                white-space: nowrap;
            }
            
            .test-button:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            }
            
            .parameters-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 16px;
                margin-top: 8px;
            }
            
            .parameter-card {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 6px;
                padding: 16px;
                transition: all 0.2s ease;
            }
            
            .parameter-card.has-enum {
                border-left: 4px solid #28a745;
            }
            
            .parameter-card:hover {
                background: #e9ecef;
                border-color: #adb5bd;
            }
            
            .parameter-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 8px;
            }
            
            .param-name {
                font-weight: 600;
                color: #212529;
                font-size: 14px;
            }
            
            .param-type {
                font-size: 12px;
                padding: 2px 8px;
                border-radius: 10px;
                font-weight: 500;
            }
            
            .param-type.required {
                background: #fff5f5;
                color: #dc3545;
                border: 1px solid #f5c6cb;
            }
            
            .param-type.optional {
                background: #e6f7ff;
                color: #0366d6;
                border: 1px solid #91d5ff;
            }
            
            .param-description {
                color: #495057;
                font-size: 13px;
                line-height: 1.4;
                margin-bottom: 12px;
            }
            
            .param-enum {
                background: white;
                border: 1px solid #d1e7dd;
                border-radius: 4px;
                padding: 12px;
                margin-bottom: 12px;
            }
            
            .enum-values {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
            }
            
            .enum-value {
                background: #d1e7dd;
                color: #0f5132;
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s ease;
                border: 1px solid transparent;
            }
            
            .enum-value:hover {
                background: #badbcc;
                border-color: #0f5132;
                transform: translateY(-1px);
            }
            
            .param-details {
                display: flex;
                flex-direction: column;
                gap: 6px;
                font-size: 12px;
            }
            
            .param-detail {
                display: flex;
                align-items: baseline;
                gap: 6px;
            }
            
            .detail-label {
                color: #6c757d;
                font-weight: 500;
                min-width: 60px;
            }
            
            .detail-value {
                color: #495057;
                font-family: 'Courier New', monospace;
                background: white;
                padding: 2px 6px;
                border-radius: 3px;
                border: 1px solid #dee2e6;
                flex: 1;
                word-break: break-all;
            }
            
            .no-params {
                padding: 12px;
                background: #f8f9fa;
                border-radius: 6px;
                text-align: center;
            }
            
            /* Search filtering */
            .rpc-method.hidden {
                display: none;
            }
            
            /* Responsive */
            @media (max-width: 768px) {
                .parameters-grid {
                    grid-template-columns: 1fr;
                }
                
                .rpc-method-header {
                    flex-direction: column;
                    gap: 12px;
                }
                
                .test-button {
                    align-self: flex-start;
                }
            }
        </style>
        
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const searchInput = document.getElementById('rpc-search');
                const clearButton = document.getElementById('clear-search');
                const methodsList = document.getElementById('rpc-methods-list');
                const methodsCount = document.getElementById('methods-count');
                const methods = Array.from(methodsList.querySelectorAll('.rpc-method'));
                
                // Función para filtrar métodos
                function filterMethods(query) {
                    const searchTerm = query.toLowerCase().trim();
                    let visibleCount = 0;
                    
                    methods.forEach(method => {
                        const name = method.dataset.name;
                        const description = method.dataset.description;
                        
                        if (searchTerm === '' || 
                            name.includes(searchTerm) || 
                            description.includes(searchTerm) ||
                            method.textContent.toLowerCase().includes(searchTerm)) {
                            
                            method.classList.remove('hidden');
                            visibleCount++;
                        } else {
                            method.classList.add('hidden');
                        }
                    });
                    
                    // Actualizar contador
                    methodsCount.textContent = \`Showing \${visibleCount} of \${methods.length} methods\`;
                    
                    // Resaltar términos de búsqueda
                    if (searchTerm) {
                        highlightSearchTerms(searchTerm);
                    } else {
                        removeHighlights();
                    }
                }
                
                // Resaltar términos de búsqueda
                function highlightSearchTerms(term) {
                    const regex = new RegExp(\`(\${term.replace(/[.*+?^${'${'}()|[\]\\]/g, '\\\\$&')})\`, 'gi');
                    
                    methods.forEach(method => {
                        if (!method.classList.contains('hidden')) {
                            const html = method.innerHTML;
                            const highlighted = html.replace(regex, '<mark style="background: #fff3cd; padding: 0 2px; border-radius: 2px;">$1</mark>');
                            method.innerHTML = highlighted;
                        }
                    });
                }
                
                // Remover resaltados
                function removeHighlights() {
                    methods.forEach(method => {
                        method.innerHTML = method.innerHTML.replace(/<mark[^>]*>([^<]*)<\/mark>/gi, '$1');
                    });
                }
                
                // Event listeners
                searchInput.addEventListener('input', function() {
                    filterMethods(this.value);
                });
                
                clearButton.addEventListener('click', function() {
                    searchInput.value = '';
                    filterMethods('');
                    searchInput.focus();
                });
                
                // Permitir buscar con Enter
                searchInput.addEventListener('keyup', function(e) {
                    if (e.key === 'Enter') {
                        const visibleMethods = methodsList.querySelectorAll('.rpc-method:not(.hidden)');
                        if (visibleMethods.length > 0) {
                            visibleMethods[0].scrollIntoView({ 
                                behavior: 'smooth', 
                                block: 'center' 
                            });
                        }
                    }
                });
                
                // Manejar clicks en valores enum
                document.addEventListener('click', function(e) {
                    if (e.target.classList.contains('enum-value')) {
                        const paramCard = e.target.closest('.parameter-card');
                        const paramName = paramCard.querySelector('.param-name').textContent;
                        
                        // Crear mensaje para copiar
                        const value = e.target.textContent;
                        const message = \`Click "Test" button and use "\${value}" for \${paramName}\`;
                        
                        // Mostrar tooltip
                        const tooltip = document.createElement('div');
                        tooltip.style.cssText = \`
                            position: fixed;
                            top: \${e.clientY + 10}px;
                            left: \${e.clientX}px;
                            background: #28a745;
                            color: white;
                            padding: 8px 12px;
                            border-radius: 4px;
                            font-size: 12px;
                            z-index: 10000;
                            pointer-events: none;
                            animation: fadeOut 2s forwards;
                        \`;
                        
                        tooltip.textContent = message;
                        document.body.appendChild(tooltip);
                        
                        // Remover después de 2 segundos
                        setTimeout(() => {
                            document.body.removeChild(tooltip);
                        }, 2000);
                        
                        // Añadir estilo CSS para la animación
                        if (!document.getElementById('tooltip-animation')) {
                            const style = document.createElement('style');
                            style.id = 'tooltip-animation';
                            style.textContent = \`
                                @keyframes fadeOut {
                                    0% { opacity: 1; transform: translateY(0); }
                                    70% { opacity: 1; transform: translateY(-5px); }
                                    100% { opacity: 0; transform: translateY(-10px); }
                                }
                            \`;
                            document.head.appendChild(style);
                        }
                    }
                });
                
                // Inicializar
                filterMethods('');
            });
        </script>
    `;

        return {
            html: html,
            methods: methods,
            count: methods.length,
            timestamp: new Date().toISOString()
        };
    }

    /**
     * Función global para probar métodos RPC desde la documentación generada
     * Esta función se asigna a window.testRpcMethod para que sea accesible desde los botones
     */createGlobalTestRpcMethod() {
        const client = this;

        window.testRpcMethod = function (methodName) {
            console.group(`🧪 testRpcMethod: ${methodName} (using .then())`);

            const methodInfo = client.getRpcMethodInfo(methodName);
            if (!methodInfo) {
                alert(`Method ${methodName} not found`);
                console.groupEnd();
                return;
            }

            // 1. Crear diálogo simple
            const dialog = document.createElement('dialog');
            dialog.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            border: 1px solid #ccc;
            border-radius: 8px;
            padding: 20px;
            background: white;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            width: 600px;
            max-width: 90vw;
            max-height: 90vh;
            overflow: auto;
            z-index: 1000;
        `;

            dialog.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0; color: #0366d6;">Test RPC: ${methodName}</h2>
                <button id="close-dialog" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
            </div>
            
            <p style="margin: 0 0 20px 0; color: #666;">${methodInfo.description}</p>
            
            <div id="params-container" style="margin-bottom: 20px;"></div>
            
  <!--          <div style="margin-bottom: 20px; padding: 10px; background: #f8f9fa; border-radius: 4px; border: 1px solid #eee;">
                <h4 style="margin: 0 0 10px 0;">Quick Actions:</h4>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button id="test-empty" style="padding: 8px 16px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        Test Empty
                    </button>
                    <button id="test-defaults" style="padding: 8px 16px; background: #17a2b8; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        Test Defaults
                    </button>
                    <button id="test-example" style="padding: 8px 16px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        Test with Example
                    </button>
                </div>
            </div>-->
            
            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                <button id="execute-test" style="padding: 12px 24px; background: #0366d6; color: white; border: none; border-radius: 4px; cursor: pointer; flex: 1; font-weight: bold;">
                    Execute RPC
                </button>
                <button id="cancel-test" style="padding: 12px 24px; background: #f0f0f0; color: #333; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">
                    Cancel
                </button>
            </div>
            
            <div id="status-container" style="margin: 20px 0;"></div>
            
            <div id="test-result" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px; border-left: 4px solid #28a745; display: none;">
                <h3 style="margin: 0 0 10px 0; color: #28a745;">✅ Success</h3>
                <pre id="result-content" style="margin: 0; white-space: pre-wrap; word-wrap: break-word; max-height: 300px; overflow: auto; background: white; padding: 10px; border-radius: 4px;"></pre>
            </div>
            
            <div id="test-error" style="margin-top: 20px; padding: 15px; background: #fee; border-radius: 4px; border-left: 4px solid #dc3545; display: none;">
                <h3 style="margin: 0 0 10px 0; color: #dc3545;">❌ Error</h3>
                <pre id="error-content" style="margin: 0; white-space: pre-wrap; word-wrap: break-word; max-height: 300px; overflow: auto; background: #fff5f5; padding: 10px; border-radius: 4px;"></pre>
            </div>
            
            <div id="debug-console" style="margin-top: 20px; padding: 10px; background: #333; color: #0f0; border-radius: 4px; font-family: monospace; font-size: 12px; max-height: 200px; overflow: auto; display: none;">
                <h4 style="margin: 0 0 10px 0; color: #0f0;">Debug Console</h4>
                <div id="debug-output" style="white-space: pre-wrap;"></div>
            </div>
        `;

            document.body.appendChild(dialog);

            // Elementos del DOM
            const paramsContainer = dialog.querySelector('#params-container');
            const statusContainer = dialog.querySelector('#status-container');
            const debugConsole = dialog.querySelector('#debug-console');
            const debugOutput = dialog.querySelector('#debug-output');

            // Helper para log en la consola de debug
            function debugLog(message, data = null) {
                const timestamp = new Date().toLocaleTimeString();
                const entry = `[${timestamp}] ${message}` + (data ? `: ${JSON.stringify(data, null, 2)}` : '');

                console.log(entry);

                // Agregar a consola de debug
                if (debugOutput) {
                    debugOutput.innerHTML = entry + '\n' + debugOutput.innerHTML;
                    debugConsole.style.display = 'block';
                }
            }

            // Helper para mostrar estado
            function showStatus(message, type = 'info') {
                const colors = {
                    info: '#0366d6',
                    success: '#28a745',
                    warning: '#ffc107',
                    error: '#dc3545'
                };

                statusContainer.innerHTML = `
                <div style="padding: 10px; background: ${colors[type]}15; border: 1px solid ${colors[type]}; border-radius: 4px; color: ${colors[type]};">
                    ${message}
                </div>
            `;
            }

            // Helper para limpiar resultados
            function clearResults() {
                dialog.querySelector('#test-result').style.display = 'none';
                dialog.querySelector('#test-error').style.display = 'none';
                statusContainer.innerHTML = '';
            }

            // Helper para convertir tipos de PHP a JS
            function phpToJsType(phpType) {
                const typeMap = {
                    'int': 'integer',
                    'integer': 'integer',
                    'float': 'number',
                    'double': 'number',
                    'bool': 'boolean',
                    'boolean': 'boolean',
                    'array': 'object', // En PHP arrays asociativos son objetos en JS
                    'string': 'string',
                    'mixed': 'any'
                };

                return typeMap[phpType.toLowerCase()] || 'string';
            }

            // Helper para crear valor por defecto basado en tipo
            function getDefaultValueForType(type) {
                const jsType = phpToJsType(type);

                switch (jsType) {
                    case 'integer':
                    case 'number':
                        return null;
                    case 'boolean':
                        return null;
                    case 'object':
                        return {};
                    case 'array':
                        return [];
                    case 'string':
                        return null;
                    default:
                        return null;
                }
            }

            // Crear inputs para parámetros
            // Dentro de createGlobalTestRpcMethod, en la sección de creación de inputs:
            if (methodInfo.params && methodInfo.params.length > 0) {
                paramsContainer.innerHTML = `
        <h3 style="margin: 0 0 15px 0;">Parameters</h3>
        ${methodInfo.params.map((param, index) => {
                    const hasEnum = param.enum && Array.isArray(param.enum) && param.enum.length > 0;

                    // Determinar valor por defecto
                    let defaultValue = '';
                    if (param.default !== null && param.default !== undefined) {
                        defaultValue = typeof param.default === 'string'
                            ? param.default
                            : JSON.stringify(param.default);
                    }

                    // Si tiene enum, usar el primer valor como default si no hay otro
                    if (hasEnum && !defaultValue && param.enum.length > 0) {
                        defaultValue = param.enum[0];
                    }

                    return `
                <div style="margin-bottom: 20px;" data-param="${param.name}">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label style="font-weight: 600; color: #333;">
                            ${param.name}
                            ${param.required ? '<span style="color: #dc3545;">*</span>' : ''}
                        </label>
                        <span style="font-size: 12px; color: #666;">
                            ${param.type}
                        </span>
                    </div>
                    
                    ${param.description ? `
                        <div style="color: #666; font-size: 14px; margin-bottom: 8px;">
                            ${param.description}
                        </div>
                    ` : ''}
                    
                    ${hasEnum ? `
                        <div style="margin-bottom: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px; border: 1px solid #dee2e6;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 6px;">Select from allowed values:</div>
                            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                ${param.enum.map(value => `
                                    <button type="button" 
                                            class="enum-selector" 
                                            data-value="${value}"
                                            style="padding: 6px 12px; background: #e9ecef; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer; font-size: 13px; transition: all 0.2s;">
                                        ${value}
                                    </button>
                                `).join('')}
                            </div>
                        </div>
                    ` : ''}
                    
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input 
                            type="text" 
                            id="param-${param.name}" 
                            class="param-input ${hasEnum ? 'has-enum' : ''}"
                            data-type="${param.type}"
                            data-required="${param.required}"
                            style="flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-family: ${hasEnum ? 'inherit' : 'monospace'};"
                            placeholder="${hasEnum ? 'Select from options above' : 'Enter value'}"
                            value="${defaultValue}"
                            ${hasEnum ? 'readonly' : ''}
                        />
                        <button type="button" 
                                class="clear-param" 
                                style="padding: 10px 16px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; white-space: nowrap;">
                            Clear
                        </button>
                    </div>
                    
                    ${param.default !== null && param.default !== undefined ? `
                        <div style="margin-top: 6px; font-size: 12px; color: #666;">
                            Default: <code style="background: #f8f9fa; padding: 2px 4px; border-radius: 2px;">${typeof param.default === 'string' ? param.default : JSON.stringify(param.default)}</code>
                        </div>
                    ` : ''}
                </div>
            `;
                }).join('')}
    `;

                // Manejar selectores de enum
                dialog.querySelectorAll('.enum-selector').forEach(button => {
                    button.addEventListener('click', function() {
                        const value = this.dataset.value;
                        const paramDiv = this.closest('[data-param]');
                        const paramName = paramDiv.dataset.param;
                        const input = dialog.querySelector(`#param-${paramName}`);

                        // Actualizar input
                        if (input) {
                            input.value = value;

                            // Resaltar botón seleccionado
                            paramDiv.querySelectorAll('.enum-selector').forEach(btn => {
                                btn.style.background = '#e9ecef';
                                btn.style.borderColor = '#ced4da';
                                btn.style.color = '#212529';
                            });

                            this.style.background = '#28a745';
                            this.style.borderColor = '#28a745';
                            this.style.color = 'white';
                        }
                    });

                    // Resaltar botón que coincide con el valor actual
                    const paramDiv = button.closest('[data-param]');
                    const paramName = paramDiv.dataset.param;
                    const input = dialog.querySelector(`#param-${paramName}`);

                    if (input && input.value === button.dataset.value) {
                        button.style.background = '#28a745';
                        button.style.borderColor = '#28a745';
                        button.style.color = 'white';
                    }
                });
            }else {
                paramsContainer.innerHTML = '<p style="color: #666; font-style: italic; padding: 20px; text-align: center;">No parameters required for this method.</p>';
            }

            // Helper para recolectar parámetros del formulario
            function collectParams() {
                const params = {};

                if (methodInfo.params) {
                    methodInfo.params.forEach(param => {
                        const input = dialog.querySelector(`#param-${param.name}`);
                        let value = input ? input.value.trim() : null;
                        if(value === 'null') value = null;
                        // Si está vacío y no es requerido, usar null
                        if (!value && !param.required) {
                            //  params[param.name] = null;
                            return;
                        }

                        // Convertir según tipo de PHP
                        try {
                            switch (param.type.toLowerCase()) {
                                case 'int':
                                case 'integer':
                                    params[param.name] = value ? parseInt(value, 10) : null;
                                    break;

                                case 'float':
                                case 'double':
                                    params[param.name] = value ? parseFloat(value) : null;
                                    break;

                                case 'bool':
                                case 'boolean':
                                    if (typeof value === 'string') {
                                        params[param.name] = value.toLowerCase() === 'true' || value === '1';
                                    } else {
                                        params[param.name] = Boolean(value);
                                    }
                                    break;

                                case 'array':
                                    // Para PHP, arrays asociativos pueden ser objetos en JS
                                    if (value) {
                                        try {
                                            const parsed = JSON.parse(value);
                                            params[param.name] = Array.isArray(parsed) ? parsed :
                                                (typeof parsed === 'object' ? parsed : [parsed]);
                                        } catch {
                                            // Si no es JSON válido, crear array con el valor
                                            params[param.name] = [value];
                                        }
                                    } else {
                                        params[param.name] = [];
                                    }
                                    break;

                                case 'object':
                                    if (value) {
                                        try {
                                            params[param.name] = JSON.parse(value);
                                        } catch {
                                            params[param.name] = {value: value};
                                        }
                                    } else {
                                        params[param.name] = {};
                                    }
                                    break;

                                default:
                                    // String y otros tipos
                                    params[param.name] = value;
                            }
                        } catch (error) {
                            debugLog(`Error parsing param ${param.name}`, error);
                            params[param.name] = value; // Fallback al valor original
                        }
                    });
                }

                return params;
            }

            // Función para ejecutar RPC con .then()
            function executeRpcWithThen(params) {
                debugLog('Starting RPC execution', {method: methodName, params: params});
                clearResults();
                showStatus('Sending RPC request...', 'info');

                // Deshabilitar botón de ejecución
                const executeButton = dialog.querySelector('#execute-test');
                const originalText = executeButton.textContent;
                executeButton.textContent = 'Processing...';
                executeButton.disabled = true;

                // Usar .then() en lugar de await
                const rpcPromise = client.rpc(methodName, params, 60000);

                rpcPromise
                    .then(result => {
                        debugLog('RPC successful', result);
                        showStatus('RPC completed successfully!', 'success');

                        // Mostrar resultado
                        const resultContent = dialog.querySelector('#result-content');
                        resultContent.textContent = JSON.stringify(result, null, 2);
                        dialog.querySelector('#test-result').style.display = 'block';

                        // Habilitar botón
                        executeButton.textContent = originalText;
                        executeButton.disabled = false;
                    })
                    .catch(error => {
                        debugLog('RPC failed', error);
                        showStatus(`RPC failed: ${error.message}`, 'error');

                        // Mostrar error
                        const errorContent = dialog.querySelector('#error-content');
                        errorContent.textContent = error.message + '\n\nStack trace:\n' + (error.stack || 'No stack trace');
                        dialog.querySelector('#test-error').style.display = 'block';

                        // Habilitar botón
                        executeButton.textContent = originalText;
                        executeButton.disabled = false;
                    });

                return rpcPromise;
            }

            // Botones de acción rápida
            /*    dialog.querySelector('#test-empty').addEventListener('click', () => {
                    debugLog('Testing with empty params');
                    executeRpcWithThen({});
                });

                dialog.querySelector('#test-defaults').addEventListener('click', () => {
                    const defaultParams = {};

                    if (methodInfo.params) {
                        methodInfo.params.forEach(param => {
                            defaultParams[param.name] = getDefaultValueForType(param.type);
                        });
                    }

                    debugLog('Testing with default values', defaultParams);
                    executeRpcWithThen(defaultParams);
                });

                dialog.querySelector('#test-example').addEventListener('click', () => {
                    const exampleParams = {};

                    if (methodInfo.params) {
                        methodInfo.params.forEach(param => {
                            if (param.example !== null && param.example !== undefined) {
                                exampleParams[param.name] = param.example;
                            } else {
                                exampleParams[param.name] = getDefaultValueForType(param.type);
                            }
                        });
                    }

                    debugLog('Testing with example values', exampleParams);
                    executeRpcWithThen(exampleParams);

                    // También actualizar los inputs con los ejemplos
                    if (methodInfo.params) {
                        methodInfo.params.forEach(param => {
                            const input = dialog.querySelector(`#param-${param.name}`);
                            if (input && param.example !== null && param.example !== undefined) {
                                input.value = typeof param.example === 'string'
                                    ? param.example
                                    : JSON.stringify(param.example);
                            }
                        });
                    }
                });
    */
            // Botón de ejecución principal
            dialog.querySelector('#execute-test').addEventListener('click', () => {
                const params = collectParams();
                debugLog('Executing with form params', params);
                executeRpcWithThen(params);
            });

            // Manejar cierre
            dialog.querySelector('#close-dialog').addEventListener('click', () => {
                document.body.removeChild(dialog);
            });

            dialog.querySelector('#cancel-test').addEventListener('click', () => {
                document.body.removeChild(dialog);
            });

            // Cerrar con Escape
            dialog.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    document.body.removeChild(dialog);
                }
            });

            // Mostrar diálogo
            dialog.show();

            // Enfocar primer input
            const firstInput = dialog.querySelector('input');
            if (firstInput) {
                firstInput.focus();
            }

            console.groupEnd();
        };

        console.log('✅ testRpcMethod creado (usando .then())');
    }
}