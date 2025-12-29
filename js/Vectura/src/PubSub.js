/**
 * A WebSocket-based publish/subscribe system for ExtJS 7
 * with JWT authentication handshake support
 */
Ext.define('Vectura.PubSub', {
    extend: 'Ext.Evented',

    requires: [
        'Ext.Deferred'
    ],

    /**
     * Event types for the WebSocket connection.
     */
    EVENT_TYPE_MESSAGE: 'message',
    EVENT_TYPE_READY: 'ready',
    EVENT_TYPE_CLOSE: 'close',
    EVENT_TYPE_RECONNECTING: 'reconnecting',
    EVENT_TYPE_SUBSCRIBED: 'subscribed',
    EVENT_TYPE_UNSUBSCRIBED: 'unsubscribed',
    EVENT_TYPE_PUBLISHED: 'published',
    EVENT_TYPE_ERROR: 'error',
    EVENT_TYPE_RPC_RESPONSE: 'rpc_response',
    EVENT_TYPE_RPC_METHODS: 'rpc_methods',
    EVENT_TYPE_RPC_REQUEST: 'rpc_request',
    EVENT_TYPE_RPC_START_EXEC: 'rpc_start_execution',
    EVENT_TYPE_RPC_ACCEPTED: 'rpc_accepted',
    EVENT_TYPE_RPC_COMPLETED: 'rpc_completed',
    EVENT_TYPE_RPC_ABORTED: 'rpc_aborted',

    /**
     * Messages types
     */
    MESSAGE_TYPE_FILE_AVAILABLE: 'file_available',
    MESSAGE_TYPE_FILE_RESPONSE: 'file_response',
    MESSAGE_TYPE_RPC_RESPONSE: 'rpc_response',
    MESSAGE_TYPE_RPC_SUCCESS: 'rpc_success',
    MESSAGE_TYPE_RPC_METHODS: 'rpc_methods_list',
    MESSAGE_TYPE_ERROR: 'error',
    MESSAGE_TYPE_MESSAGE: 'message',
    MESSAGE_TYPE_SUCCESS: 'success',

    /**
     * RPC status
     */
    RPC_STATUS_ACCEPTED: 'accepted',
    RPC_STATUS_REJECTED: 'rejected',
    RPC_STATUS_ERROR: 'error',
    RPC_STATUS_COMPLETED: 'success',

    /**
     * RPC actions
     */
    ACTION_LIST_RPC_METHODS: 'list_rpc_methods',
    ACTION_RPC_CALL: 'rpc',
    ACTION_SUBSCRIBE: 'subscribe',
    ACTION_UNSUBSCRIBE: 'unsubscribe',
    ACTION_PUBLISH: 'publish',
    ACTION_SEND_FILE: 'send_file',
    ACTION_START_FILE_TRANSFER: 'start_file_transfer',
    ACTION_FILE_CHUNK: 'file_chunk',
    ACTION_REQUEST_FILE: 'request_file',

    config: {
        /**
         * @cfg {String} url
         * The URL for the WebSocket connection.
         */
        url: null,

        /**
         * @cfg {String} token
         * JWT token for authentication.
         */
        token: null,

        /**
         * @cfg {Number} authTimeout
         * Authentication timeout in milliseconds.
         */
        authTimeout: 5000,

        /**
         * @cfg {Boolean} allowGuest
         * Allow connections without authentication.
         */
        allowGuest: true,

        /**
         * @cfg {Number} maxFileSize
         * The maximum allowed file size in bytes for transfers.
         */
        maxFileSize: 100 * 1024 * 1024, // 100MB

        /**
         * @cfg {Number} maxReconnectAttempts
         * The maximum number of reconnect attempts.
         */
        maxReconnectAttempts: 30,

        /**
         * @cfg {Boolean} verbose
         * Enables verbose logging when set to true.
         */
        verbose: false,

        /**
         * @cfg {Function} tokenRefreshFn
         * Function to refresh token automatically.
         */
        tokenRefreshFn: null,

        /**
         * @cfg {Number} tokenRefreshInterval
         * Token refresh interval in milliseconds.
         */
        tokenRefreshInterval: 5 * 60 * 1000, // 5 minutes
        /**
         * @cfg {String} rpcMethodsUrl
         * URL para cargar métodos RPC desde endpoint HTTP.
         */
        rpcMethodsUrl: null,

        /**
         * @cfg {Boolean} loadRpcMethodsOnConnect
         * Cargar métodos RPC automáticamente al conectar.
         */
        loadRpcMethodsOnConnect: true,

        /**
         * @cfg {Boolean} autoGenerateRpcStubs
         * Generar stubs de métodos RPC automáticamente.
         */
        autoGenerateRpcStubs: true,

        /**
         * @cfg {Boolean} enableRpcNamespaces
         * Habilitar namespaces para métodos RPC (ej: db.health.status).
         */
        enableRpcNamespaces: true,
        /**
         * A property to hold the base namespace for RPC (Remote Procedure Call).
         * This is typically used to define a namespace for ensuring unique
         * identification of RPC methods within a particular scope.
         */
        rpcBaseNamespace: null
    },

    /**
     * @property {WebSocket} ws
     * The WebSocket instance.
     */
    ws: null,

    /**
     * @property {Boolean} isAuthenticated
     * Authentication status flag.
     */
    isAuthenticated: false,

    /**
     * @property {Set} subscriptions
     * Active subscriptions.
     */
    subscriptions: null,

    /**
     * @property {Map} rpcHandlers
     * RPC request handlers.
     */
    rpcHandlers: null,

    /**
     * @property {Map} fileHandlers
     * File transfer handlers.
     */
    fileHandlers: null,

    /**
     * @property {Map} fileTransfers
     * Active file transfers.
     */
    fileTransfers: null,

    /**
     * @property {Map} channelCallbacks
     * Callbacks specific by channel.
     */
    channelCallbacks: null,

    /**
     * @property {Map} pendingCallbacks
     * Callbacks pending reconnection.
     */
    pendingCallbacks: null,

    /**
     * @property {Number} chunkSize
     * File chunk size (64KB).
     */
    chunkSize: 64 * 1024,

    /**
     * @property {Number} reconnectAttempts
     * Current reconnect attempts.
     */
    reconnectAttempts: 0,

    /**
     * @property {Number} reconnectDelay
     * Current reconnect delay.
     */
    reconnectDelay: 1000,

    /**
     * @property {Set} toResubscribe
     * Channels to resubscribe on reconnect.
     */
    toResubscribe: null,

    /**
     * @property {Boolean} isManualClose
     * Flag for manual connection close.
     */
    isManualClose: false,

    /**
     * @property {Number} authTimeoutId
     * Authentication timeout timer ID.
     */
    authTimeoutId: null,

    /**
     * @property {Number} tokenRefreshTimer
     * Token refresh timer ID.
     */
    tokenRefreshTimer: null,

    /**
     * Constructor
     * @param {Object} config Configuration object
     */
    constructor: function (config) {
        this.callParent([config]);
        this.initConfig(config);

        this.isAuthenticated = false;
        this.subscriptions = new Set();
        this.rpcHandlers = new Map();
        this.fileHandlers = new Map();
        this.fileTransfers = new Map();
        this.channelCallbacks = new Map();
        this.pendingCallbacks = new Map();
        this.toResubscribe = new Set();
        this.rpcMethodsMetadata = new Map(); // Almacena metadatos de métodos
        this.rpcStubsGenerated = false;
        this.rpcNamespaces = {}; // Almacena namespaces creados

        if (this.getVerbose()) {
            this.log = Ext.log.bind(Ext);
            this.error = Ext.log.bind(Ext, 'error');
            this.debug = Ext.log.bind(Ext, 'debug');
            this.info = Ext.log.bind(Ext, 'info');
        } else {
            this.log = this.error = this.debug = this.info = Ext.emptyFn;
        }

        // Setup token refresh if configured
        if (this.getTokenRefreshFn()) {
            this.setupTokenRefresh();
        }
    },

    /**
     * Establishes a WebSocket connection.
     */
    connect: function () {
        if (this.isManualClose) {
            this.debug('Manually closed connection, do not reconnect');
            return;
        }

        try {
            this.ws = new WebSocket(this.getUrl());
            this.isAuthenticated = false;
            this.setupEventListeners();
        } catch (error) {
            this.error('Error creating WebSocket:', error);
            this.scheduleReconnect();
        }
    },

    /**
     * Sets up WebSocket event listeners.
     */
    setupEventListeners: function () {
        var me = this;

        this.ws.onopen = function () {
            me.log('WebSocket connected, authenticating...');

            // If there is a token or guests are not allowed, authenticate
            if (me.getToken() || !me.getAllowGuest()) {
                me.authenticate();
            } else {
                me.onAuthenticated();
            }

            me.reconnectAttempts = 0;
            me.reconnectDelay = 1000;

            if (me.toResubscribe.size > 0) {
                me.toResubscribe.forEach(function (channel) {
                    me.subscribe(channel);
                });
                me.toResubscribe.clear();
            }

            // Restore pending callbacks
            me.restorePendingCallbacks();
        };

        this.ws.onmessage = function (event) {
            me.debug('Message received:', event);
            try {
                var data = Ext.decode(event.data);

                // Check if it's an authentication response
                if (data.type === 'auth_response') {
                    me.handleAuthResponse(data);
                    return;
                }

                // Only process other messages if authenticated
                if (me.isAuthenticated) {
                    me.handleMessage(data);
                }
            } catch (error) {
                me.error('Error parsing message:', error);
                me.fireEvent(me.EVENT_TYPE_ERROR, {
                    message: 'Error parsing message',
                    error: error
                });
            }
        };

        this.ws.onclose = function (event) {
            me.info('Connection closed. Code: ' + event.code + ', Reason: ' + event.reason);

            if (!me.isManualClose && !event.wasClean &&
                me.reconnectAttempts < me.getMaxReconnectAttempts()) {

                // Save current subscriptions and callbacks for reconnection
                me.subscriptions.forEach(function (channel) {
                    if (!me.toResubscribe.has(channel)) {
                        me.toResubscribe.add(channel);
                    }

                    // Save callbacks associated with this channel
                    var callbacks = me.channelCallbacks.get(channel);
                    if (callbacks && callbacks.length > 0 && !me.pendingCallbacks.has(channel)) {
                        me.pendingCallbacks.set(channel, {
                            callback: callbacks[0].fn,
                            scope: callbacks[0].scope
                        });
                    }
                });

                me.scheduleReconnect();
            } else {
                if (!me.isManualClose && !event.wasClean &&
                    me.reconnectAttempts === me.getMaxReconnectAttempts()) {

                    me.error('Unable to connect to Pub/Sub server, connection attempts exceeded.');
                    me.fireEvent(me.EVENT_TYPE_ERROR, {
                        message: 'The maximum number of attempts to connect to the Pub/Sub server has been reached.'
                    });
                } else {
                    me.debug('Connection closed cleanly', event);
                    me.fireEvent(me.EVENT_TYPE_CLOSE, {
                        wasClean: event.wasClean,
                        code: event.code,
                        reason: event.reason
                    });
                    me.isManualClose = false;
                }
            }

            me.ws = null;
            me.subscriptions.clear();
            me.channelCallbacks.clear();
        };

        this.ws.onerror = function (error) {
            me.error('WebSocket Error:', error);
            me.fireEvent(me.EVENT_TYPE_ERROR, {
                message: 'WebSocket Error. Status: ' + me.getStatus(),
                error: error
            });
        };
    },

    /**
     * Sends token for authentication.
     */
    authenticate: function () {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            return;
        }

        var me = this;
        var authTimeout = this.getAuthTimeout();

        // Timeout for authentication
        this.authTimeoutId = Ext.defer(function () {
            if (!me.isAuthenticated) {
                me.error('Authentication timeout');
                me.ws.close(4001, 'Authentication timeout');
            }
        }, authTimeout);

        // Send token
        this.ws.send(Ext.encode({
            action: 'authenticate',
            token: this.getToken()
        }));
    },

    /**
     * Updates token and reauthenticates if needed.
     * @param {String} newToken New JWT token
     * @param {Boolean} reauthenticate Whether to reauthenticate
     */
    updateToken: function (newToken, reauthenticate) {
        reauthenticate = reauthenticate !== false;
        this.setToken(newToken);

        if (reauthenticate && this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.isAuthenticated = false;
            this.authenticate();
        }
    },

    /**
     * Handles authentication response.
     * @param {Object} data Response data
     */
    handleAuthResponse: function (data) {
        Ext.undefer(this.authTimeoutId);

        if (data.success) {
            this.onAuthenticated();
        } else {
            this.error('Authentication failed:', data.error);
            this.fireEvent('auth_error', {
                error: data.error
            });
            this.ws.close(4000, 'Authentication failed');
        }
    },

    /**
     * Called when authentication is successful.
     */
    onAuthenticated: function () {
        const me = this
        this.isAuthenticated = true;
        this.log('Authenticated with Pub/Sub server');
        // Cargar métodos RPC si está configurado
        if (me.getLoadRpcMethodsOnConnect() && me.getRpcMethodsUrl()) {
            me.loadRpcMethodsFromUrl().then(function() {
                me.fireEvent(me.EVENT_TYPE_READY, {
                    subscriptions: me.subscriptions,
                    rpcMethods: me.rpcMethodsMetadata
                });
            }).catch(function(error) {
                me.warn('Failed to load RPC methods:', error);
                me.fireEvent(me.EVENT_TYPE_READY, {
                    subscriptions: me.subscriptions
                });
            });
        } else {
            me.fireEvent(me.EVENT_TYPE_READY, {
                subscriptions: me.subscriptions
            });
        }
    },

    /**
     * Sets up automatic token refresh.
     */
    setupTokenRefresh: function () {
        var me = this;
        var interval = this.getTokenRefreshInterval();

        if (interval > 0) {
            this.tokenRefreshTimer = Ext.interval(function () {
                me.refreshToken();
            }, interval);
        }

        // Listen for authentication errors
        this.on('auth_error', function (error) {
            me.refreshToken();
        });
    },

    /**
     * Refreshes token automatically.
     */
    refreshToken: function () {
        var refreshFn = this.getTokenRefreshFn();
        if (!refreshFn) {
            return;
        }

        var me = this;
        Ext.Promise.resolve(refreshFn()).then(function (newToken) {
            me.updateToken(newToken, true);
        }).catch(function (error) {
            me.error('Error refreshing token:', error);
        });
    },

    /**
     * Restores pending callbacks after reconnection.
     */
    restorePendingCallbacks: function () {
        var me = this;
        this.pendingCallbacks.forEach(function (callbackObj, channel) {
            me.addChannelCallback(channel, callbackObj.callback, callbackObj.scope);
        });
        this.pendingCallbacks.clear();
    },

    /**
     * Subscribes to a channel with optional callback.
     * @param {String} channel The channel name
     * @param {Function} callback Optional callback function
     * @param {Object} scope Optional callback scope
     */
    subscribe: function (channel, callback, scope) {
        if (!this.isReady()) {
            if (!this.isAuthenticated) {
                this.debug('Not authenticated, saving subscription');
            } else {
                this.debug('WebSocket is not ready, saving your subscription to try again later');
            }

            if (!this.toResubscribe.has(channel)) {
                this.toResubscribe.add(channel);
            }

            // Save pending callback if provided
            if (callback) {
                this.pendingCallbacks.set(channel, {
                    callback: callback,
                    scope: scope
                });
            }

            return;
        }

        if (this.subscriptions.has(channel)) {
            this.log('Channel already registered: ', channel);
            // Still register callback if provided
            if (callback) {
                this.addChannelCallback(channel, callback, scope);
            }
            return;
        }

        this.ws.send(Ext.encode({
            action: this.ACTION_SUBSCRIBE,
            channel: channel
        }));

        this.subscriptions.add(channel);
        this.log('Subscribed to the channel:', channel);

        // Register callback if provided
        if (callback) {
            this.addChannelCallback(channel, callback, scope);
        }

        this.fireEvent(this.EVENT_TYPE_SUBSCRIBED, channel);
    },

    /**
     * Adds a callback for a specific channel.
     * @private
     * @param {String} channel The channel
     * @param {Function} callback Callback function
     * @param {Object} scope Callback scope
     */
    addChannelCallback: function (channel, callback, scope) {
        var callbacks = this.channelCallbacks.get(channel) || [];
        callbacks.push({
            fn: callback,
            scope: scope || this
        });
        this.channelCallbacks.set(channel, callbacks);
    },

    /**
     * Unsubscribes from a channel, optionally a specific callback.
     * @param {String} channel The channel name
     * @param {Function} callback Optional specific callback to remove
     */
    unsubscribe: function (channel, callback) {
        if (!this.isReady()) {
            return;
        }

        // If no callback specified, unsubscribe completely
        if (!callback) {
            this.ws.send(Ext.encode({
                action: this.ACTION_UNSUBSCRIBE,
                channel: channel
            }));

            this.subscriptions.delete(channel);
            this.toResubscribe.delete(channel);
            this.channelCallbacks.delete(channel);
            this.pendingCallbacks.delete(channel);

            this.log('Unsubscribed from the channel:', channel);
            this.fireEvent(this.EVENT_TYPE_UNSUBSCRIBED, channel);
        } else {
            // Only remove specific callback
            var callbacks = this.channelCallbacks.get(channel);
            if (callbacks) {
                var newCallbacks = callbacks.filter(function (cb) {
                    return cb.fn !== callback;
                });

                if (newCallbacks.length === 0) {
                    // If no callbacks left, unsubscribe from server
                    this.unsubscribe(channel);
                } else {
                    this.channelCallbacks.set(channel, newCallbacks);
                    this.log('Callback removed from channel:', channel);
                }
            }
        }
    },

    /**
     * Publishes a message to a channel.
     * @param {String} channel The channel name
     * @param {Object} data The message data
     */
    publish: function (channel, data) {
        if (!this.isReady()) {
            this.fireEvent(this.EVENT_TYPE_ERROR, {
                message: 'WebSocket is not connected, please reconnect and try again'
            });
            return;
        }

        this.ws.send(Ext.encode({
            action: this.ACTION_PUBLISH,
            channel: channel,
            data: data
        }));

        this.debug('Published message on channel:', channel);
        this.fireEvent(this.EVENT_TYPE_PUBLISHED, {
            channel: channel,
            data: data
        });
    },

    /**
     * Handles incoming messages.
     * @param {Object} data The message data
     */
    handleMessage: function (data) {
        switch (data.type) {
            case this.MESSAGE_TYPE_MESSAGE:
                // 1. Fire general event (compatibility)
                this.fireEvent(this.EVENT_TYPE_MESSAGE, data);

                // 2. Execute channel-specific callbacks
                if (data.channel) {
                    this.executeChannelCallbacks(data.channel, data.data);
                }
                break;

            case this.MESSAGE_TYPE_FILE_RESPONSE:
                this.handleFileResponse(data);
                break;

            case this.MESSAGE_TYPE_FILE_AVAILABLE:
                this.fireEvent('file_available', data);
                break;

            case this.MESSAGE_TYPE_RPC_ERROR:
            case this.MESSAGE_TYPE_RPC_RESPONSE:
            case this.MESSAGE_TYPE_RPC_SUCCESS:
                this.debug('RPC response received:', data);
                this.handleRpcResponse(data);
                break;

            case this.MESSAGE_TYPE_RPC_METHODS:
                this.fireEvent('rpc_methods', data.methods);
                break;

            case this.MESSAGE_TYPE_SUCCESS:
                this.log('Process completed successfully: ', data.message);
                this.fireEvent('success', data);
                break;

            case this.MESSAGE_TYPE_ERROR:
                this.error('Error:', data.message);
                this.fireEvent('error', {
                    message: data.message,
                    error: data.error
                });
                break;
        }
    },

    /**
     * Executes all callbacks registered for a channel.
     * @private
     * @param {String} channel The channel
     * @param {Object} data The message data
     */
    executeChannelCallbacks: function (channel, data) {
        var callbacks = this.channelCallbacks.get(channel);
        var me = this;

        if (callbacks) {
            callbacks.forEach(function (callbackObj) {
                try {
                    callbackObj.fn.call(callbackObj.scope, data);
                } catch (error) {
                    me.error('Error in channel callback for ' + channel + ':', error);
                }
            });
        }
    },

    /**
     * Returns a fluent API object for a specific channel.
     * @param {String} channel The channel
     * @param {Object} scope Optional scope for callbacks
     * @return {Object} Fluent interface for the channel
     */
    channel: function (channel, scope) {
        var me = this;
        var actualScope = scope || this;

        return {
            /**
             * Subscribe to the channel with a callback.
             * @param {Function} callback The callback
             * @return {Object} this for chaining
             */
            subscribe: function (callback) {
                me.subscribe(channel, callback, actualScope);
                return this;
            },

            /**
             * Unsubscribe from the channel.
             * @param {Function} callback Optional specific callback to remove
             * @return {Object} this for chaining
             */
            unsubscribe: function (callback) {
                me.unsubscribe(channel, callback);
                return this;
            },

            /**
             * Publish to the channel.
             * @param {Object} data The data to publish
             * @return {void}
             */
            publish: function (data) {
                me.publish(channel, data);
            },

            /**
             * Check if subscribed to the channel.
             * @return {Boolean}
             */
            isSubscribed: function () {
                return me.subscriptions.has(channel);
            }
        };
    },

    /**
     * Schedules a reconnection attempt.
     */
    scheduleReconnect: function () {
        var me = this;

        var delay = Math.min(
            this.reconnectDelay * Math.pow(2, this.reconnectAttempts),
            30000 // Maximum 30 seconds
        );

        var logMsg = 'Reconnecting in ' + delay + ' ms (Attempt ' +
            (this.reconnectAttempts + 1) + '/' + this.getMaxReconnectAttempts() + ')';

        this.log(logMsg);

        this.fireEvent(this.EVENT_TYPE_RECONNECTING, {
            message: logMsg,
            reconnectAttempts: this.reconnectAttempts + 1,
            maxReconnectAttempts: this.getMaxReconnectAttempts(),
            delay: delay
        });

        Ext.defer(function () {
            me.reconnectAttempts++;
            me.connect();
        }, delay);
    },

    /**
     * Disconnects from the server.
     */
    disconnect: function () {
        this.isManualClose = true;

        if (this.ws) {
            var me = this;

            this.subscriptions.forEach(function (channel) {
                me.unsubscribe(channel);
            });

            Ext.defer(function () {
                me.ws.close(1000, 'Manual disconnect');
                me.ws = null;
            }, 500);

            this.subscriptions.clear();
            this.channelCallbacks.clear();
            this.pendingCallbacks.clear();
            this.reconnectAttempts = 0;
            this.reconnectDelay = 1000;
            this.toResubscribe.clear();

            this.debug('Disconnected from the Pub/Sub server');
        }
    },

    /**
     * Sends a file to a channel.
     * @param {String} channel The channel name
     * @param {File} file The file to send
     * @param {Object} metadata Optional metadata
     * @param {Number} timeout Timeout in milliseconds
     * @return {Ext.Promise} Promise that resolves when file is sent
     */
    sendFile: function (channel, file, metadata, timeout) {
        var me = this;

        metadata = metadata || {};
        timeout = timeout || 30000;

        if (!this.isReady()) {
            return Ext.Promise.reject(new Error('WebSocket is not connected'));
        }

        if (file.size > this.getMaxFileSize()) {
            return Ext.Promise.reject(
                new Error('The file exceeds the limit of ' +
                    this.getMaxFileSize() / 1024 / 1024 + ' MB.')
            );
        }

        if (file.size > 25 * 1024 * 1024) { // 25MB
            return this.sendLargeFile(channel, file, metadata);
        }

        var deferred = new Ext.Deferred();
        var reader = new FileReader();
        var requestId = this.generateRequestId();
        var timeoutId;

        // Timeout
        if (timeout > 0) {
            timeoutId = Ext.defer(function () {
                me.fileHandlers.delete(requestId);
                deferred.reject(new Error('Timeout sending file'));
            }, timeout);
        }

        // Save handler
        this.fileHandlers.set(requestId, {
            resolve: function (data) {
                Ext.undefer(timeoutId);
                deferred.resolve(data);
            },
            reject: function (error) {
                Ext.undefer(timeoutId);
                deferred.reject(error);
            }
        });

        reader.onload = function () {
            var base64Data = reader.result.split(',')[1];

            me.ws.send(Ext.encode({
                action: me.ACTION_SEND_FILE,
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

        reader.onerror = function () {
            deferred.reject(new Error('Error reading file'));
        };

        reader.readAsDataURL(file);

        return deferred.promise;
    },

    /**
     * Sends a large file in chunks.
     * @param {String} channel The channel name
     * @param {File} file The file to send
     * @param {Object} metadata Optional metadata
     * @return {Ext.Promise} Promise that resolves when file is sent
     */
    sendLargeFile: function (channel, file, metadata) {
        var me = this;

        metadata = metadata || {};

        if (!this.isReady()) {
            return Ext.Promise.reject(new Error('WebSocket is not connected'));
        }

        var fileId = this.generateRequestId();
        var totalChunks = Math.ceil(file.size / this.chunkSize);
        var currentChunk = 0;
        var deferred = new Ext.Deferred();

        this.fileTransfers.set(fileId, {
            file: file,
            totalChunks: totalChunks,
            receivedChunks: 0,
            chunks: new Array(totalChunks),
            resolve: deferred.resolve,
            reject: deferred.reject
        });

        // Send metadata first
        this.ws.send(Ext.encode({
            action: this.ACTION_START_FILE_TRANSFER,
            channel: channel,
            file_id: fileId,
            file_name: file.name,
            file_type: file.type,
            file_size: file.size,
            total_chunks: totalChunks,
            metadata: metadata
        }));

        // Send chunks
        this.sendNextChunk(fileId, currentChunk);

        return deferred.promise;
    },

    /**
     * Sends the next chunk of a file.
     * @param {String} fileId The file ID
     * @param {Number} chunkIndex The chunk index
     */
    sendNextChunk: function (fileId, chunkIndex) {
        var me = this;
        var transfer = this.fileTransfers.get(fileId);

        if (!transfer || chunkIndex >= transfer.totalChunks) {
            return;
        }

        var start = chunkIndex * this.chunkSize;
        var end = Math.min(start + this.chunkSize, transfer.file.size);
        var chunk = transfer.file.slice(start, end);

        var reader = new FileReader();
        reader.onload = function () {
            var base64Data = reader.result.split(',')[1];

            me.ws.send(Ext.encode({
                action: me.ACTION_FILE_CHUNK,
                file_id: fileId,
                chunk_index: chunkIndex,
                chunk_data: base64Data,
                is_last: chunkIndex === transfer.totalChunks - 1
            }));

            // Schedule next chunk
            if (chunkIndex < transfer.totalChunks - 1) {
                Ext.defer(function () {
                    me.sendNextChunk(fileId, chunkIndex + 1);
                }, 0);
            }
        };

        reader.readAsDataURL(chunk);
    },

    /**
     * Requests a file download.
     * @param {String} fileId The file ID
     * @param {String} fileName Optional file name
     * @return {Ext.Promise} Promise that resolves with file data
     */
    requestFile: function (fileId, fileName) {
        fileName = fileName || 'download';

        if (!this.isReady()) {
            return Ext.Promise.reject(new Error('WebSocket is not connected'));
        }

        var deferred = new Ext.Deferred();
        var requestId = this.generateRequestId();

        this.fileHandlers.set(requestId, {
            resolve: deferred.resolve,
            reject: deferred.reject
        });

        this.ws.send(Ext.encode({
            action: this.ACTION_REQUEST_FILE,
            request_id: requestId,
            file_id: fileId,
            file_name: fileName
        }));

        return deferred.promise;
    },

    /**
     * Handles file responses.
     * @param {Object} data The response data
     */
    handleFileResponse: function (data) {
        var handler = this.fileHandlers.get(data.request_id);

        if (handler) {
            this.fileHandlers.delete(data.request_id);

            if (data.success) {
                // Create blob and URL for download
                var binaryData = atob(data.file.data);
                var bytes = new Uint8Array(binaryData.length);

                for (var i = 0; i < binaryData.length; i++) {
                    bytes[i] = binaryData.charCodeAt(i);
                }

                var blob = new Blob([bytes], {type: data.file.type});
                var url = URL.createObjectURL(blob);

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
    },

    /**
     * Handles RPC responses.
     * @param {Object} data The response data
     */
    handleRpcResponse: function (data) {
        var handler = this.rpcHandlers.get(data.id);

        if (!handler) {
            this.debug('No handler for RPC ID:', data.id);
            return;
        }

        switch (data.status) {
            case this.RPC_STATUS_ACCEPTED:
                this.fireEvent(this.EVENT_TYPE_RPC_ACCEPTED, {
                    id: data.id,
                    worker_id: data.worker_id,
                    method: handler.method,
                    timestamp: data.timestamp
                });

                this.debug('RPC ' + data.id + ' (' + handler.method +
                    ') accepted by worker ' + data.worker_id);
                break;

            case this.RPC_STATUS_COMPLETED:
                this.debug('RPC ' + data.id + ' (' + handler.method +
                    ') completed in ' + (Date.now() - handler.startTime) + 'ms');

                handler.resolve(data.result || data.message);
                this.rpcHandlers.delete(data.id);

                this.fireEvent(this.EVENT_TYPE_RPC_COMPLETED, {
                    id: data.id,
                    method: handler.method,
                    result: data.result || data.message,
                    execution_time: data.execution_time,
                    timestamp: data.timestamp
                });
                break;

            case this.RPC_STATUS_ERROR:
            case this.RPC_STATUS_REJECTED:
                this.error('RPC ' + data.id + ' (' + handler.method + ') failed:',
                    data.error ? data.error.message : 'Unknown error');

                handler.reject(new Error(
                    data.error ? data.error.message : 'Unknown RPC error'
                ));

                this.rpcHandlers.delete(data.id);

                this.fireEvent(this.EVENT_TYPE_ERROR, {
                    message: 'RPC ' + data.id + ' (' + handler.method + ') failed: ' +
                        (data.error ? data.error.message : 'Unknown RPC error')
                });

                this.fireEvent(this.EVENT_TYPE_RPC_ABORTED, {
                    id: data.id,
                    method: handler.method,
                    error: data.error ? data.error.message : 'Unknown RPC error',
                    timestamp: data.timestamp
                });
                break;

            default:
                this.warn('Unknown RPC status for ' + data.id + ':', data.status);
        }
    },

    /**
     * Executes a remote RPC call.
     * @param {String} method The method name
     * @param {Object} params The method parameters
     * @param {Number} timeout Timeout in milliseconds
     * @return {Ext.Promise} Promise that resolves with the result
     */
    rpc: function (method, params, timeout) {
        var me = this;

        params = params || {};
        timeout = timeout || 30000;

        if (!this.isReady()) {
            return Ext.Promise.reject(
                new Error('WebSocket not connected. Cannot execute RPC.')
            );
        }

        var deferred = new Ext.Deferred();
        var requestId = this.generateRequestId();
        var timeoutId;

        this.fireEvent(this.EVENT_TYPE_RPC_START_EXEC, {
            id: requestId,
            method: method
        });

        var cleanup = function () {
            Ext.undefer(timeoutId);
            me.rpcHandlers.delete(requestId);
        };

        // Timeout
        if (timeout > 0) {
            timeoutId = Ext.defer(function () {
                cleanup();
                deferred.reject(
                    new Error('RPC timeout(' + timeout + 'ms) on method: ' + method)
                );
            }, timeout);
        }

        // Save handler
        this.rpcHandlers.set(requestId, {
            resolve: function (data) {
                cleanup();
                deferred.resolve(data);
            },
            reject: function (error) {
                cleanup();
                deferred.reject(error);
            },
            method: method,
            startTime: Date.now()
        });

        // Send request
        this.ws.send(Ext.encode({
            action: this.ACTION_RPC_CALL,
            id: requestId,
            method: method,
            params: params,
            timeout: Math.floor(timeout / 1000) // send in seconds
        }));

        this.debug('RPC ' + requestId + ' sent: ' + method);

        return deferred.promise;
    },

    /**
     * Lists available RPC methods.
     * @return {Ext.Promise} Promise that resolves with the methods list
    listRpcMethods: function () {
        var me = this;

        if (!this.isReady()) {
            return Ext.Promise.reject(
                new Error('WebSocket disconnected. Cannot list RPC methods.')
            );
        }

        var deferred = new Ext.Deferred();

        var handler = function (methods) {
            me.un(me.EVENT_TYPE_RPC_METHODS, handler);
            deferred.resolve(methods);
        };

        this.on(me.EVENT_TYPE_RPC_METHODS, handler);
        this.ws.send(Ext.encode({action: this.ACTION_LIST_RPC_METHODS}));

        return deferred.promise;
    },
        */
    /**
     * Carga métodos RPC desde un endpoint HTTP.
     * @param {String} url URL opcional (usa la configurada por defecto)
     * @return {Ext.Promise} Promise que resuelve con la lista de métodos
     */
    loadRpcMethodsFromUrl: function(url) {
        var me = this;
        var targetUrl = url || me.getRpcMethodsUrl();

        if (!targetUrl) {
            return Ext.Promise.reject(new Error('No RPC methods URL specified'));
        }

        me.debug('Loading RPC methods from:', targetUrl);

        return Ext.Ajax.request({
            url: targetUrl,
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        }).then(function(response) {
            var data = Ext.decode(response.responseText);

            if (data.type !== 'rpc' || !Ext.isArray(data.methods)) {
                throw new Error('Invalid RPC methods response');
            }

            // Almacenar metadatos
            me.rpcMethodsMetadata.clear();
            data.methods.forEach(function(method) {
                me.rpcMethodsMetadata.set(method.method, method);
            });

            me.debug('Loaded ' + data.methods.length + ' RPC methods');

            // Generar stubs automáticamente si está configurado
            if (me.getAutoGenerateRpcStubs()) {
                me.generateRpcStubs();
            }

            // Disparar evento
            me.fireEvent(me.EVENT_TYPE_RPC_METHODS, data.methods);

            return data.methods;

        }).catch(function(error) {
            me.error('Error loading RPC methods:', error);
            throw error;
        });
    },

    /**
     * Genera funciones stub para todos los métodos RPC cargados.
     */
    generateRpcStubs: function() {
        var me = this;

        if (me.rpcStubsGenerated) {
            me.debug('RPC stubs already generated');
            return;
        }

        // Generar stubs para cada método
        me.rpcMethodsMetadata.forEach(function(methodInfo, methodName) {
            // Crear función para este método
            me[methodName] = me.createRpcStubFunction(methodName, methodInfo);

            // También crear versión con namespaces si está habilitado
            if (me.getEnableRpcNamespaces() && methodName.includes('.')) {
                me.createNamespacedRpcStub(methodName, methodInfo);
            }
        });

        me.rpcStubsGenerated = true;
        me.debug('Generated RPC stubs for ' + me.rpcMethodsMetadata.size + ' methods');
    },

    /**
     * Crea una función stub individual para un método RPC.
     * @private
     * @param {String} methodName Nombre del método
     * @param {Object} methodInfo Información del método
     * @return {Function} Función stub
     */
    createRpcStubFunction: function(methodName, methodInfo) {
        var me = this;

        return function() {
            var args = Ext.Array.from(arguments);
            var params = {};

            // Si es un solo argumento y es objeto, usarlo directamente
            if (args.length === 1 && Ext.isObject(args[0]) && !Ext.isArray(args[0])) {
                params = args[0];
            }
            // Si hay múltiples argumentos y conocemos los nombres de parámetros
            else if (methodInfo.params && Ext.isArray(methodInfo.params) && methodInfo.params.length > 0) {
                // Crear mapeo de parámetros por nombre
                var paramMap = {};
                methodInfo.params.forEach(function(param) {
                    paramMap[param.name] = param;
                });

                var paramNames = methodInfo.params.map(function(p) {
                    return p.name;
                });

                paramNames.forEach(function(paramName, index) {
                    if (index < args.length) {
                        params[paramName] = args[index];
                    } else {
                        // Usar valor por defecto si existe
                        var paramDef = paramMap[paramName];
                        if (paramDef && paramDef.default !== null) {
                            params[paramName] = paramDef.default;
                        }
                    }
                });
            }
            // Fallback: pasar todos los args como array
            else if (args.length > 0) {
                params = { args: args };
            }

            // Validar parámetros requeridos
            var missingParams = me.validateRpcParameters(methodInfo, params);
            if (missingParams.length > 0) {
                return Ext.Promise.reject(new Error(
                    'Missing required parameters for ' + methodName + ': ' + missingParams.join(', ')
                ));
            }

            // Llamar al método RPC
            return me.rpc(methodName, params);
        };
    },

    /**
     * Crea stubs namespaced (ej: client.db.health.status()).
     * @private
     * @param {String} fullName Nombre completo del método con puntos
     * @param {Object} methodInfo Información del método
     */
    createNamespacedRpcStub: function(fullName, methodInfo) {
        var me = this;
        var parts = fullName.split('.');
        var current = this.getRpcBaseNamespace() || me;
        if (typeof current !== 'object') {
            if (current === null) {
                current = this;
            }
            if (typeof current === 'string') {
                if(!window[current]) {
                    window[current] = {};
                }
                current = window[current];
            }
        }

        // Crear namespaces anidados
        for (var i = 0; i < parts.length - 1; i++) {
            var part = parts[i];

            // Crear namespace si no existe
            if (!current[part] || !Ext.isObject(current[part])) {
                current[part] = {};
            }
            current = current[part];
        }

        // Asignar el método al último namespace
        var methodName = parts[parts.length - 1];
        current[methodName] = me.createRpcStubFunction(fullName, methodInfo);

        // Registrar namespace
        if (!me.rpcNamespaces[parts[0]]) {
            me.rpcNamespaces[parts[0]] = {};
        }
    },

    /**
     * Valida los parámetros de un método RPC.
     * @private
     * @param {Object} methodInfo Información del método
     * @param {Object} params Parámetros proporcionados
     * @return {Array} Lista de parámetros requeridos faltantes
     */
    validateRpcParameters: function(methodInfo, params) {
        var missing = [];

        if (Ext.isArray(methodInfo.params)) {
            methodInfo.params.forEach(function(param) {
                if (param.required &&
                    (params[param.name] === undefined || params[param.name] === null)) {
                    missing.push(param.name);
                }
            });
        }

        return missing;
    },

    /**
     * Obtiene información de un método RPC específico.
     * @param {String} methodName Nombre del método
     * @return {Object|null} Información del método
     */
    getRpcMethodInfo: function(methodName) {
        return this.rpcMethodsMetadata.get(methodName);
    },

    /**
     * Lista todos los métodos RPC disponibles.
     * @return {Array} Lista de métodos
     */
    listRpcMethods: function() {
        return Ext.Array.from(this.rpcMethodsMetadata.values());
    },

    /**
     * Busca métodos RPC por nombre o descripción.
     * @param {String} query Término de búsqueda
     * @return {Array} Métodos que coinciden
     */
    searchRpcMethods: function(query) {
        query = query.toLowerCase();

        return Ext.Array.from(this.rpcMethodsMetadata.values()).filter(function(method) {
            return method.method.toLowerCase().indexOf(query) !== -1 ||
                method.description.toLowerCase().indexOf(query) !== -1;
        });
    },
    /**
     * Generates a unique request ID.
     * @return {String} The generated ID
     */
    generateRequestId: function () {
        return Date.now().toString(36) + Math.random().toString(36).substr(2);
    },

    /**
     * Downloads a file automatically.
     * @param {Object} blobData The blob data
     * @param {String} fileName Optional file name
     */
    downloadFile: function (blobData, fileName) {
        var link = document.createElement('a');
        link.href = blobData.url;
        link.download = fileName || blobData.name;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // Release URL after a while
        Ext.defer(function () {
            URL.revokeObjectURL(blobData.url);
        }, 1000);
    },

    /**
     * Checks if the WebSocket is ready.
     * @return {Boolean} True if ready
     */
    isReady: function () {
        return this.ws &&
            this.ws.readyState === WebSocket.OPEN &&
            this.isAuthenticated;
    },

    /**
     * Gets the current connection status.
     * @return {String} The status
     */
    getStatus: function () {
        if (!this.ws) return 'disconnected';

        switch (this.ws.readyState) {
            case WebSocket.CONNECTING:
                return 'connecting';
            case WebSocket.OPEN:
                return this.isAuthenticated ? 'connected' : 'authenticating';
            case WebSocket.CLOSING:
                return 'closing';
            case WebSocket.CLOSED:
                return 'closed';
            default:
                return 'unknown';
        }
    },

    /**
     * Returns subscribed channels.
     * @return {Array} Array of channel names
     */
    getSubscribedChannels: function () {
        return Ext.Array.from(this.subscriptions);
    },

    /**
     * Checks if subscribed to a specific channel.
     * @param {String} channel The channel
     * @return {Boolean} True if subscribed
     */
    hasSubscription: function (channel) {
        return this.subscriptions.has(channel);
    },

    /**
     * Cleans up token refresh timer.
     */
    cleanupTokenRefresh: function () {
        if (this.tokenRefreshTimer) {
            Ext.undefer(this.tokenRefreshTimer);
            this.tokenRefreshTimer = null;
        }
    },

    /**
     * Destroys the instance.
     */
    destroy: function () {
        this.cleanupTokenRefresh();
        this.disconnect();
        this.callParent();
    },
    /**
     * Crea campos de formulario para probar un método RPC con soporte para enum
     * @private
     * @param {Object} methodInfo Información del método
     * @return {Array} Campos del formulario
     */
    createRpcTestFormFields: function(methodInfo) {
        var fields = [];
        var me = this;

        // Descripción
        fields.push({
            xtype: 'component',
            html: '<div style="padding: 10px; background: #f8f9fa; border-radius: 4px; margin-bottom: 15px;">' +
                '<strong>' + methodInfo.method + '</strong><br/>' +
                '<span style="color: #666;">' + methodInfo.description + '</span>' +
                '</div>'
        });

        // Campos de parámetros
        if (Ext.isArray(methodInfo.params) && methodInfo.params.length > 0) {
            methodInfo.params.forEach(function(param) {
                var hasEnum = param.enum && Ext.isArray(param.enum) && param.enum.length > 0;

                var fieldConfig = {
                    xtype: hasEnum ? 'combo' : 'textfield',
                    fieldLabel: param.name,
                    labelWidth: 120,
                    allowBlank: !param.required,
                    name: param.name,
                    anchor: '100%',
                    margin: '0 0 10 0',
                    paramType: param.type,
                    paramRequired: param.required
                };

                // Configuración específica para combos (enum)
                if (hasEnum) {
                    Ext.apply(fieldConfig, {
                        xtype: 'combo',
                        displayField: 'value',
                        valueField: 'value',
                        queryMode: 'local',
                        triggerAction: 'all',
                        editable: false,
                        forceSelection: true,
                        store: Ext.create('Ext.data.Store', {
                            fields: ['value'],
                            data: param.enum.map(function(value) {
                                return { value: value };
                            })
                        }),
                        emptyText: 'Select a value...'
                    });

                    // Establecer valor por defecto
                    if (param.default !== null && param.default !== undefined) {
                        fieldConfig.value = param.default;
                    } else if (param.enum.length > 0) {
                        fieldConfig.value = param.enum[0];
                    }
                } else {
                    // Configuración para campos de texto normales
                    fieldConfig.emptyText = param.example !== null ?
                        'Example: ' + JSON.stringify(param.example) :
                        param.default !== null ?
                            'Default: ' + JSON.stringify(param.default) :
                            'Enter value';

                    // Establecer valor por defecto
                    if (param.default !== null && param.default !== undefined) {
                        fieldConfig.value = typeof param.default === 'string' ?
                            param.default : JSON.stringify(param.default);
                    }
                }

                // Añadir información de tipo
                var typeLabel = '(' + param.type + (param.required ? ', required' : ', optional') + ')';
                if (hasEnum) {
                    typeLabel += ' - ' + param.enum.length + ' options';
                }

                fieldConfig.afterLabelTextTpl = [
                    '<span style="color:#666;font-size:11px;margin-left:5px;">',
                    typeLabel,
                    '</span>'
                ];

                // Añadir tooltip con descripción
                if (param.description) {
                    fieldConfig.tooltip = param.description;
                    fieldConfig.tooltipType = 'title';
                }

                // Añadir ayuda para enum
                if (hasEnum && param.description) {
                    fieldConfig.helpText = param.description +
                        '<br/><strong>Allowed values:</strong> ' + param.enum.join(', ');
                } else if (param.description) {
                    fieldConfig.helpText = param.description;
                }

                fields.push(fieldConfig);

                // Si tiene enum, añadir botones rápidos
                if (hasEnum) {
                    fields.push({
                        xtype: 'container',
                        layout: {
                            type: 'hbox',
                            pack: 'start',
                            align: 'stretch'
                        },
                        margin: '0 0 15 120',
                        defaults: {
                            margin: '0 5px 0 0'
                        },
                        items: param.enum.map(function(value) {
                            return {
                                xtype: 'button',
                                text: value,
                                handler: function() {
                                    var form = this.up('form');
                                    var field = form.down('[name="' + param.name + '"]');
                                    if (field) {
                                        field.setValue(value);
                                    }
                                },
                                tooltip: 'Set to: ' + value,
                                scale: 'small'
                            };
                        })
                    });
                }
            });
        } else {
            fields.push({
                xtype: 'component',
                html: '<div style="text-align: center; padding: 20px; color: #999; font-style: italic;">' +
                    'No parameters required for this method.' +
                    '</div>'
            });
        }

        // Campo para resultado
        fields.push({
            xtype: 'textarea',
            fieldLabel: 'Result',
            labelWidth: 120,
            name: 'result',
            anchor: '100%',
            height: 200,
            readOnly: true,
            margin: '20 0 0 0',
            hidden: true,
            fieldStyle: {
                fontFamily: 'monospace',
                fontSize: '12px'
            }
        });

        return fields;
    },
    /**
     * Genera HTML de documentación en un panel con soporte para enum
     * @private
     * @param {Ext.panel.Panel} panel Panel destino
     */
    generateRpcDocsHtml: function(panel) {
        var me = this;
        var methods = me.listRpcMethods();
        var container = panel.down('#docsContainer').getEl();

        if (methods.length === 0) {
            container.setHtml('<div class="rpc-empty">No RPC methods available</div>');
            return;
        }

        var html = [
            '<div class="rpc-header">',
            '<h2>Available RPC Methods (' + methods.length + ')</h2>',
            '<p class="rpc-status">',
            'Server: <strong>' + me.getUrl() + '</strong> | ',
            'Status: <span class="rpc-status-badge ' + (me.isReady() ? 'connected' : 'disconnected') + '">',
            me.isReady() ? 'Connected' : 'Disconnected',
            '</span>',
            '</p>',
            '</div>',

            '<div class="rpc-search-container">',
            '<input type="text" id="rpc-search" placeholder="Search methods..." class="rpc-search-input">',
            '<button id="clear-search" class="rpc-search-clear">Clear</button>',
            '</div>',

            '<div id="methods-count" class="methods-count">Showing ' + methods.length + ' methods</div>',

            '<div class="rpc-methods-list">'
        ];

        methods.forEach(function(method, index) {
            html.push(
                '<div class="rpc-method" id="method-' + method.method.replace(/\./g, '-') + '" ' +
                'data-name="' + method.method.toLowerCase() + '" ' +
                'data-description="' + Ext.String.htmlEncode(method.description).toLowerCase() + '">',

                '<div class="rpc-method-header">',
                '<div class="method-title">',
                '<h3>' + method.method + '</h3>',
                '<div class="method-metadata">',
                '<span class="method-id">#' + (index + 1) + '</span>',
                '<span class="method-params">' + (method.params ? method.params.length : 0) + ' params</span>',
                method.allow_guest ?
                    '<span class="method-guest">Guest Allowed</span>' :
                    '<span class="method-auth">Requires Auth</span>',
                '</div>',
                '</div>',
                '<button class="rpc-test-btn" onclick="Ext.getCmp(\'' + panel.getId() + '\').testRpcMethod(\'' +
                method.method + '\')">Test Method</button>',
                '</div>',

                '<p class="rpc-method-desc">' + Ext.String.htmlEncode(method.description) + '</p>'
            );

            if (Ext.isArray(method.params) && method.params.length > 0) {
                html.push(
                    '<div class="rpc-params">',
                    '<h4>Parameters:</h4>',
                    '<div class="params-grid">'
                );

                method.params.forEach(function(param) {
                    var hasEnum = param.enum && Ext.isArray(param.enum) && param.enum.length > 0;

                    html.push(
                        '<div class="param-card' + (hasEnum ? ' has-enum' : '') + '">',
                        '<div class="param-header">',
                        '<strong>' + param.name + '</strong>',
                        '<span class="param-type ' + (param.required ? 'required' : 'optional') + '">',
                        param.type + (param.required ? ' *' : ''),
                        '</span>',
                        '</div>'
                    );

                    if (param.description) {
                        html.push(
                            '<div class="param-desc">' + Ext.String.htmlEncode(param.description) + '</div>'
                        );
                    }

                    // Mostrar valores enum si existen
                    if (hasEnum) {
                        html.push(
                            '<div class="param-enum">',
                            '<div class="enum-label">Allowed values:</div>',
                            '<div class="enum-values">'
                        );

                        param.enum.forEach(function(value) {
                            html.push(
                                '<span class="enum-value" title="Click to use this value" data-param="' + param.name + '" data-value="' +
                                Ext.String.htmlEncode(value) + '">' + Ext.String.htmlEncode(value) + '</span>'
                            );
                        });

                        html.push('</div></div>');
                    }

                    // Mostrar default y example
                    html.push('<div class="param-details">');

                    if (param.default !== null && param.default !== undefined) {
                        html.push(
                            '<div class="param-detail">',
                            '<span class="detail-label">Default:</span>',
                            '<span class="detail-value">' + Ext.String.htmlEncode(
                                typeof param.default === 'string' ? param.default : JSON.stringify(param.default)
                            ) + '</span>',
                            '</div>'
                        );
                    }

                    if (param.example !== null && param.example !== undefined) {
                        html.push(
                            '<div class="param-detail">',
                            '<span class="detail-label">Example:</span>',
                            '<span class="detail-value">' + Ext.String.htmlEncode(
                                typeof param.example === 'string' ? param.example : JSON.stringify(param.example)
                            ) + '</span>',
                            '</div>'
                        );
                    }

                    html.push('</div></div>');
                });

                html.push('</div></div>');
            } else {
                html.push('<p class="no-params">No parameters required</p>');
            }

            html.push('</div>');
        });

        html.push('</div>');

        if (methods.length === 0) {
            html.push(
                '<div class="rpc-empty">',
                '<p>No RPC methods available</p>',
                '<button class="reload-btn" onclick="location.reload()">Reload Methods</button>',
                '</div>'
            );
        }

        container.setHtml(html.join(''));

        // Añadir estilos CSS actualizados
        if (!me.rpcDocsCssAdded) {
            var css = [
                '.rpc-docs-container { font-family: inherit; }',
                '.rpc-empty { text-align: center; padding: 40px; color: #999; }',
                '.rpc-header { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee; }',
                '.rpc-header h2 { margin: 0 0 10px 0; color: #333; }',
                '.rpc-status { margin: 0; color: #666; }',
                '.rpc-status-badge { padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: bold; }',
                '.rpc-status-badge.connected { background: #e6f7ff; color: #0366d6; border: 1px solid #91d5ff; }',
                '.rpc-status-badge.disconnected { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; }',

                '.rpc-search-container { margin: 15px 0; display: flex; gap: 10px; }',
                '.rpc-search-input { flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }',
                '.rpc-search-clear { padding: 8px 16px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; }',
                '.methods-count { margin: 10px 0; color: #666; font-size: 14px; }',

                '.rpc-methods-list { max-width: 100%; }',
                '.rpc-method { border: 1px solid #e8e8e8; margin: 15px 0; padding: 20px; border-radius: 8px; background: white; }',
                '.rpc-method-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }',
                '.method-title { flex: 1; }',
                '.rpc-method h3 { margin: 0; color: #0366d6; font-size: 16px; font-weight: 600; }',
                '.method-metadata { display: flex; gap: 10px; font-size: 12px; color: #666; margin-top: 5px; }',
                '.method-id, .method-params, .method-guest, .method-auth { padding: 1px 6px; border-radius: 3px; }',
                '.method-guest { background: #e6f7ff; color: #0366d6; }',
                '.method-auth { background: #fff1f0; color: #cf1322; }',

                '.rpc-method-desc { margin: 0 0 15px 0; color: #666; line-height: 1.5; }',

                '.rpc-params { margin: 15px 0; }',
                '.rpc-params h4 { margin: 0 0 12px 0; font-size: 14px; color: #333; font-weight: 600; }',
                '.params-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 15px; }',

                '.param-card { border: 1px solid #e8e8e8; border-radius: 6px; padding: 15px; background: #fafafa; }',
                '.param-card.has-enum { border-left: 4px solid #28a745; }',
                '.param-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }',
                '.param-type { font-size: 12px; padding: 2px 8px; border-radius: 10px; font-weight: 500; }',
                '.param-type.required { background: #fff5f5; color: #dc3545; border: 1px solid #f5c6cb; }',
                '.param-type.optional { background: #e6f7ff; color: #0366d6; border: 1px solid #91d5ff; }',

                '.param-desc { color: #666; font-size: 13px; line-height: 1.4; margin-bottom: 10px; }',

                '.param-enum { background: white; border: 1px solid #d1e7dd; border-radius: 4px; padding: 10px; margin: 10px 0; }',
                '.enum-label { font-size: 12px; color: #0f5132; margin-bottom: 6px; font-weight: 500; }',
                '.enum-values { display: flex; flex-wrap: wrap; gap: 5px; }',
                '.enum-value { background: #d1e7dd; color: #0f5132; padding: 4px 8px; border-radius: 4px; font-size: 12px; cursor: pointer; transition: all 0.2s; }',
                '.enum-value:hover { background: #badbcc; transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }',

                '.param-details { margin-top: 10px; font-size: 12px; }',
                '.param-detail { display: flex; margin-bottom: 4px; }',
                '.detail-label { color: #666; min-width: 60px; }',
                '.detail-value { color: #333; font-family: monospace; background: white; padding: 2px 6px; border-radius: 3px; border: 1px solid #e8e8e8; }',

                '.no-params { color: #999; font-style: italic; text-align: center; padding: 20px; }',

                '.rpc-test-btn { padding: 8px 16px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; }',
                '.rpc-test-btn:hover { opacity: 0.9; }',

                '.reload-btn { padding: 8px 16px; background: #0366d6; color: white; border: none; border-radius: 4px; cursor: pointer; margin-top: 10px; }',

                /* Estilos para búsqueda */
                '.rpc-method.hidden { display: none; }',
                '.search-highlight { background: #fff3cd; padding: 0 2px; border-radius: 2px; }',

                /* Responsive */
                '@media (max-width: 768px) {',
                '  .params-grid { grid-template-columns: 1fr; }',
                '  .rpc-method-header { flex-direction: column; gap: 10px; }',
                '  .rpc-test-btn { align-self: flex-start; }',
                '}'
            ].join(' ');

            Ext.util.CSS.createStyleSheet(css, 'rpc-docs-styles');
            me.rpcDocsCssAdded = true;
        }

        // Añadir funcionalidad de búsqueda
        Ext.defer(function() {
            var searchInput = container.dom.querySelector('#rpc-search');
            var clearButton = container.dom.querySelector('#clear-search');
            var methodsCount = container.dom.querySelector('#methods-count');
            var methodElements = container.dom.querySelectorAll('.rpc-method');

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var query = this.value.toLowerCase().trim();
                    var visibleCount = 0;

                    methodElements.forEach(function(method) {
                        var name = method.getAttribute('data-name');
                        var description = method.getAttribute('data-description');

                        if (query === '' || name.includes(query) || description.includes(query)) {
                            method.classList.remove('hidden');
                            visibleCount++;

                            // Resaltar término de búsqueda
                            if (query) {
                                var text = method.textContent.toLowerCase();
                                var regex = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
                                method.innerHTML = method.innerHTML.replace(regex, '<span class="search-highlight">$1</span>');
                            }
                        } else {
                            method.classList.add('hidden');
                        }
                    });

                    if (methodsCount) {
                        methodsCount.textContent = 'Showing ' + visibleCount + ' of ' + methodElements.length + ' methods';
                    }
                });
            }

            if (clearButton) {
                clearButton.addEventListener('click', function() {
                    if (searchInput) {
                        searchInput.value = '';
                        searchInput.dispatchEvent(new Event('input'));
                        searchInput.focus();
                    }
                });
            }

            // Manejar clicks en valores enum
            container.dom.addEventListener('click', function(e) {
                if (e.target.classList.contains('enum-value')) {
                    var paramName = e.target.getAttribute('data-param');
                    var value = e.target.getAttribute('data-value');

                    // Mostrar mensaje usando ExtJS
                    Ext.toast({
                        html: 'Click "Test Method" and use <strong>' + value + '</strong> for ' + paramName,
                        align: 't',
                        slideInDuration: 300,
                        slideBackDuration: 200,
                        minWidth: 300,
                        header: false
                    });
                }
            });

        }, 100);
    },
    /**
     * Método de prueba para ser llamado desde los botones (actualizado para enum)
     * @param {String} methodName Nombre del método a probar
     */
    testRpcMethod: function(methodName) {
        var me = this;
        var methodInfo = me.getRpcMethodInfo(methodName);

        if (!methodInfo) {
            Ext.Msg.alert('Error', 'Method ' + methodName + ' not found');
            return;
        }

        // Crear ventana de prueba
        var win = Ext.create('Ext.window.Window', {
            title: 'Test RPC: ' + methodName,
            width: 700,
            height: 600,
            modal: true,
            layout: 'fit',
            maximizable: true,

            tools: [{
                type: 'help',
                tooltip: 'Method Info',
                handler: function() {
                    Ext.Msg.show({
                        title: 'Method Information',
                        message: '<strong>' + methodName + '</strong><br/><br/>' +
                            '<strong>Description:</strong> ' + methodInfo.description + '<br/><br/>' +
                            '<strong>Parameters:</strong> ' + (methodInfo.params ? methodInfo.params.length : 0) + '<br/>' +
                            '<strong>Guest Access:</strong> ' + (methodInfo.allow_guest ? 'Yes' : 'No'),
                        buttons: Ext.Msg.OK,
                        icon: Ext.Msg.INFO
                    });
                }
            }],

            items: [{
                xtype: 'form',
                padding: 10,
                layout: 'anchor',
                scrollable: 'y',
                autoScroll: true,

                fieldDefaults: {
                    labelWidth: 120,
                    anchor: '100%',
                    margin: '0 0 10 0'
                },

                items: me.createRpcTestFormFields(methodInfo),

                buttons: [{
                    text: 'Use Defaults',
                    iconCls: 'x-fa fa-magic',
                    handler: function() {
                        var form = this.up('form');
                        me.fillFormWithDefaults(form, methodInfo);
                    }
                }, {
                    text: 'Clear',
                    iconCls: 'x-fa fa-eraser',
                    handler: function() {
                        this.up('form').getForm().reset();
                    }
                }, '->', {
                    text: 'Cancel',
                    iconCls: 'x-fa fa-times',
                    handler: function() {
                        win.close();
                    }
                }, {
                    text: 'Execute',
                    formBind: true,
                    iconCls: 'x-fa fa-play',
                    handler: function() {
                        me.executeRpcTest(win, methodName);
                    }
                }]
            }]
        });

        win.show();
    },

    /**
     * Llena el formulario con valores por defecto
     * @private
     * @param {Ext.form.Panel} form Formulario
     * @param {Object} methodInfo Información del método
     */
    fillFormWithDefaults: function(form, methodInfo) {
        var form = form.getForm();

        if (Ext.isArray(methodInfo.params)) {
            methodInfo.params.forEach(function(param) {
                var field = form.findField(param.name);
                if (field) {
                    if (param.default !== null && param.default !== undefined) {
                        field.setValue(param.default);
                    } else if (param.enum && Ext.isArray(param.enum) && param.enum.length > 0) {
                        // Para combos con enum, seleccionar el primer valor
                        field.setValue(param.enum[0]);
                    } else {
                        // Valores por defecto basados en tipo
                        switch(param.type.toLowerCase()) {
                            case 'int':
                            case 'integer':
                                field.setValue(0);
                                break;
                            case 'bool':
                            case 'boolean':
                                field.setValue(false);
                                break;
                            case 'array':
                                field.setValue('[]');
                                break;
                            case 'object':
                                field.setValue('{}');
                                break;
                            default:
                                field.setValue('');
                        }
                    }
                }
            });
        }
    },
    /**
     * Crea un componente de documentación RPC reutilizable
     * @return {Ext.Component} Componente de documentación
     */
    createRpcDocumentationComponent: function() {
        var me = this;

        return Ext.create('Ext.panel.Panel', {
            title: 'RPC Methods Documentation',
            layout: 'fit',
            scrollable: true,
            padding: 10,
            cls: 'rpc-docs-panel',

            tools: [{
                type: 'refresh',
                tooltip: 'Reload Methods',
                handler: function() {
                    me.reloadRpcMethods();
                }
            }, {
                type: 'search',
                tooltip: 'Search Methods',
                handler: function() {
                    var panel = this.up('panel');
                    var searchField = panel.down('#searchField');
                    if (searchField) {
                        searchField.focus();
                    }
                }
            }],

            items: [{
                xtype: 'container',
                layout: 'vbox',
                items: [{
                    xtype: 'container',
                    layout: 'hbox',
                    padding: '0 0 10 0',
                    items: [{
                        xtype: 'textfield',
                        itemId: 'searchField',
                        fieldLabel: 'Search',
                        labelWidth: 60,
                        emptyText: 'Type method name or description...',
                        flex: 1,
                        enableKeyEvents: true,
                        listeners: {
                            keyup: {
                                fn: function(field) {
                                    me.filterRpcMethods(field.getValue());
                                },
                                buffer: 300
                            }
                        }
                    }, {
                        xtype: 'button',
                        text: 'Clear',
                        margin: '0 0 0 5',
                        handler: function() {
                            var field = this.up('container').down('#searchField');
                            if (field) {
                                field.setValue('');
                                me.filterRpcMethods('');
                            }
                        }
                    }]
                }, {
                    xtype: 'component',
                    itemId: 'docsContainer',
                    autoEl: {
                        tag: 'div',
                        cls: 'rpc-docs-container'
                    },
                    flex: 1
                }]
            }],

            afterRender: function() {
                this.callParent(arguments);
                me.generateRpcDocsHtml(this);
            }
        });
    },

    /**
     * Filtra métodos RPC en la documentación
     * @param {String} query Término de búsqueda
     */
    filterRpcMethods: function(query) {
        var panel = Ext.ComponentQuery.query('panel[rpcDocPanel=true]')[0];
        if (!panel) return;

        var container = panel.down('#docsContainer');
        if (!container) return;

        var methods = container.getEl().select('.rpc-method');
        var searchTerm = query.toLowerCase();

        methods.each(function(method) {
            var name = method.getAttribute('data-name').toLowerCase();
            var description = method.getAttribute('data-description').toLowerCase();

            if (searchTerm === '' || name.indexOf(searchTerm) !== -1 || description.indexOf(searchTerm) !== -1) {
                method.setStyle('display', 'block');
            } else {
                method.setStyle('display', 'none');
            }
        });

        // Actualizar contador
        var visibleCount = container.getEl().select('.rpc-method:not([style*="display: none"])').elements.length;
        var countElement = container.getEl().select('#methods-count').elements[0];
        if (countElement) {
            countElement.innerHTML = 'Showing ' + visibleCount + ' methods';
        }
    },
});