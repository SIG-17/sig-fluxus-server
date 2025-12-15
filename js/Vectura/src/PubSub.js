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
        tokenRefreshInterval: 5 * 60 * 1000 // 5 minutes
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
    constructor: function(config) {
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
    connect: function() {
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
    setupEventListeners: function() {
        var me = this;

        this.ws.onopen = function() {
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
                me.toResubscribe.forEach(function(channel) {
                    me.subscribe(channel);
                });
                me.toResubscribe.clear();
            }

            // Restore pending callbacks
            me.restorePendingCallbacks();
        };

        this.ws.onmessage = function(event) {
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

        this.ws.onclose = function(event) {
            me.info('Connection closed. Code: ' + event.code + ', Reason: ' + event.reason);

            if (!me.isManualClose && !event.wasClean &&
                me.reconnectAttempts < me.getMaxReconnectAttempts()) {

                // Save current subscriptions and callbacks for reconnection
                me.subscriptions.forEach(function(channel) {
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

        this.ws.onerror = function(error) {
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
    authenticate: function() {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            return;
        }

        var me = this;
        var authTimeout = this.getAuthTimeout();

        // Timeout for authentication
        this.authTimeoutId = Ext.defer(function() {
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
    updateToken: function(newToken, reauthenticate) {
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
    handleAuthResponse: function(data) {
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
    onAuthenticated: function() {
        this.isAuthenticated = true;
        this.log('Authenticated with Pub/Sub server');
        this.fireEvent(this.EVENT_TYPE_READY, {
            subscriptions: this.subscriptions
        });
    },

    /**
     * Sets up automatic token refresh.
     */
    setupTokenRefresh: function() {
        var me = this;
        var interval = this.getTokenRefreshInterval();

        if (interval > 0) {
            this.tokenRefreshTimer = Ext.interval(function() {
                me.refreshToken();
            }, interval);
        }

        // Listen for authentication errors
        this.on('auth_error', function(error) {
            me.refreshToken();
        });
    },

    /**
     * Refreshes token automatically.
     */
    refreshToken: function() {
        var refreshFn = this.getTokenRefreshFn();
        if (!refreshFn) {
            return;
        }

        var me = this;
        Ext.Promise.resolve(refreshFn()).then(function(newToken) {
            me.updateToken(newToken, true);
        }).catch(function(error) {
            me.error('Error refreshing token:', error);
        });
    },

    /**
     * Restores pending callbacks after reconnection.
     */
    restorePendingCallbacks: function() {
        var me = this;
        this.pendingCallbacks.forEach(function(callbackObj, channel) {
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
    subscribe: function(channel, callback, scope) {
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
    addChannelCallback: function(channel, callback, scope) {
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
    unsubscribe: function(channel, callback) {
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
                var newCallbacks = callbacks.filter(function(cb) {
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
    publish: function(channel, data) {
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
    handleMessage: function(data) {
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

            case this.MESSAGE_TYPE_RPC_RESPONSE:
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
    executeChannelCallbacks: function(channel, data) {
        var callbacks = this.channelCallbacks.get(channel);
        var me = this;

        if (callbacks) {
            callbacks.forEach(function(callbackObj) {
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
    channel: function(channel, scope) {
        var me = this;
        var actualScope = scope || this;

        return {
            /**
             * Subscribe to the channel with a callback.
             * @param {Function} callback The callback
             * @return {Object} this for chaining
             */
            subscribe: function(callback) {
                me.subscribe(channel, callback, actualScope);
                return this;
            },

            /**
             * Unsubscribe from the channel.
             * @param {Function} callback Optional specific callback to remove
             * @return {Object} this for chaining
             */
            unsubscribe: function(callback) {
                me.unsubscribe(channel, callback);
                return this;
            },

            /**
             * Publish to the channel.
             * @param {Object} data The data to publish
             * @return {void}
             */
            publish: function(data) {
                me.publish(channel, data);
            },

            /**
             * Check if subscribed to the channel.
             * @return {Boolean}
             */
            isSubscribed: function() {
                return me.subscriptions.has(channel);
            }
        };
    },

    /**
     * Schedules a reconnection attempt.
     */
    scheduleReconnect: function() {
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

        Ext.defer(function() {
            me.reconnectAttempts++;
            me.connect();
        }, delay);
    },

    /**
     * Disconnects from the server.
     */
    disconnect: function() {
        this.isManualClose = true;

        if (this.ws) {
            var me = this;

            this.subscriptions.forEach(function(channel) {
                me.unsubscribe(channel);
            });

            Ext.defer(function() {
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
    sendFile: function(channel, file, metadata, timeout) {
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
            timeoutId = Ext.defer(function() {
                me.fileHandlers.delete(requestId);
                deferred.reject(new Error('Timeout sending file'));
            }, timeout);
        }

        // Save handler
        this.fileHandlers.set(requestId, {
            resolve: function(data) {
                Ext.undefer(timeoutId);
                deferred.resolve(data);
            },
            reject: function(error) {
                Ext.undefer(timeoutId);
                deferred.reject(error);
            }
        });

        reader.onload = function() {
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

        reader.onerror = function() {
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
    sendLargeFile: function(channel, file, metadata) {
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
    sendNextChunk: function(fileId, chunkIndex) {
        var me = this;
        var transfer = this.fileTransfers.get(fileId);

        if (!transfer || chunkIndex >= transfer.totalChunks) {
            return;
        }

        var start = chunkIndex * this.chunkSize;
        var end = Math.min(start + this.chunkSize, transfer.file.size);
        var chunk = transfer.file.slice(start, end);

        var reader = new FileReader();
        reader.onload = function() {
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
                Ext.defer(function() {
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
    requestFile: function(fileId, fileName) {
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
    handleFileResponse: function(data) {
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
    handleRpcResponse: function(data) {
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

                handler.resolve(data.result);
                this.rpcHandlers.delete(data.id);

                this.fireEvent(this.EVENT_TYPE_RPC_COMPLETED, {
                    id: data.id,
                    method: handler.method,
                    result: data.result,
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
    rpc: function(method, params, timeout) {
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

        var cleanup = function() {
            Ext.undefer(timeoutId);
            me.rpcHandlers.delete(requestId);
        };

        // Timeout
        if (timeout > 0) {
            timeoutId = Ext.defer(function() {
                cleanup();
                deferred.reject(
                    new Error('RPC timeout(' + timeout + 'ms) on method: ' + method)
                );
            }, timeout);
        }

        // Save handler
        this.rpcHandlers.set(requestId, {
            resolve: function(data) {
                cleanup();
                deferred.resolve(data);
            },
            reject: function(error) {
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
     */
    listRpcMethods: function() {
        var me = this;

        if (!this.isReady()) {
            return Ext.Promise.reject(
                new Error('WebSocket disconnected. Cannot list RPC methods.')
            );
        }

        var deferred = new Ext.Deferred();

        var handler = function(methods) {
            me.un(me.EVENT_TYPE_RPC_METHODS, handler);
            deferred.resolve(methods);
        };

        this.on(me.EVENT_TYPE_RPC_METHODS, handler);
        this.ws.send(Ext.encode({action: this.ACTION_LIST_RPC_METHODS}));

        return deferred.promise;
    },

    /**
     * Generates a unique request ID.
     * @return {String} The generated ID
     */
    generateRequestId: function() {
        return Date.now().toString(36) + Math.random().toString(36).substr(2);
    },

    /**
     * Downloads a file automatically.
     * @param {Object} blobData The blob data
     * @param {String} fileName Optional file name
     */
    downloadFile: function(blobData, fileName) {
        var link = document.createElement('a');
        link.href = blobData.url;
        link.download = fileName || blobData.name;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // Release URL after a while
        Ext.defer(function() {
            URL.revokeObjectURL(blobData.url);
        }, 1000);
    },

    /**
     * Checks if the WebSocket is ready.
     * @return {Boolean} True if ready
     */
    isReady: function() {
        return this.ws &&
            this.ws.readyState === WebSocket.OPEN &&
            this.isAuthenticated;
    },

    /**
     * Gets the current connection status.
     * @return {String} The status
     */
    getStatus: function() {
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
    getSubscribedChannels: function() {
        return Ext.Array.from(this.subscriptions);
    },

    /**
     * Checks if subscribed to a specific channel.
     * @param {String} channel The channel
     * @return {Boolean} True if subscribed
     */
    hasSubscription: function(channel) {
        return this.subscriptions.has(channel);
    },

    /**
     * Cleans up token refresh timer.
     */
    cleanupTokenRefresh: function() {
        if (this.tokenRefreshTimer) {
            Ext.undefer(this.tokenRefreshTimer);
            this.tokenRefreshTimer = null;
        }
    },

    /**
     * Destroys the instance.
     */
    destroy: function() {
        this.cleanupTokenRefresh();
        this.disconnect();
        this.callParent();
    }
});