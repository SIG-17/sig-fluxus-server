# Vectura Pub/Sub JS

Sistema de publicación/suscripción basado en WebSockets que facilita la comunicación, la transferencia de archivos y las reconexiones con funciones avanzadas de gestión. Admite el patrón EventEmitter y las devoluciones de llamada clásicas de Pub/Sub.

## Versión ES6


```javascript
import { VecturaPubSub } from './vectura-pubsub.js';
```

### Ejemplos de uso con la nueva API:

1. Patrón clásico Pub/Sub (recomendado):

```javascript
const pubsub = new VecturaPubSub('ws://server');

// Suscripción con callback específico
pubsub.subscribe('notifications', (data) => {
    console.log('Notification received:', data);
});

// Múltiples callbacks para el mismo canal
pubsub.subscribe('notifications', (data) => {
    this.updateUI(data);
}, this);

// Desuscribir callback específico
const handler = (data) => console.log('Handler 2:', data);
pubsub.subscribe('orders', handler);
pubsub.unsubscribe('orders', handler); // Solo remueve este handler
```

2. Fluent API para canales:

```javascript
const notifications = pubsub.channel('notifications', this);

// Encadenamiento
notifications
    .subscribe((data) => console.log('New notification:', data))
    .subscribe((data) => this.saveNotification(data));

// Publicar
notifications.publish({ message: 'Hello' });

// Verificar suscripción
if (notifications.isSubscribed()) {
    console.log('Estoy suscrito a notifications');
}
```
3. Compatibilidad con EventTarget (original):

```javascript
// Todavía funciona
pubsub.addEventListener('message', (event) => {
    console.log('Generic message:', event.data);
});

pubsub.on('ready', () => {
    console.log('Connected!');
});
```
4. Manejo de scope:

```javascript
class MyComponent {
constructor() {
this.pubsub = new VecturaPubSub('ws://server');

        // Con binding automático
        this.pubsub.subscribe('updates', this.onUpdate, this);
    }
    
    onUpdate(data) {
        // 'this' se refiere a MyComponent
        this.processData(data);
    }
}
```

## Paquete ExtJS

```json
//Agergar path en el workspace.js para poder importar el paquete
{
    "apps": [],
    "frameworks": {"ext": "node_modules/@sencha/ext" },
    "build": {"dir": "${workspace.dir}/build"},
    "packages": {
    "dir": "${workspace.dir}/node_modules/vectura-pubsub/js,${workspace.dir}/packages/framework,${workspace.dir}/packages/local, (etc)...",
        "extract": "${workspace.dir}/packages/remote"
    }
}
```
Modos de uso disponibles:

1. Modo EventEmitter (compatibilidad):

```javascript
pubsub.on('message', function(event) {
    if (event.data.channel === 'notificaciones') {
        console.log('Notificación:', event.data.data);
    }
});
```
2. Modo Pub/Sub Clásico (recomendado):

```javascript
// Suscripción con callback específico
pubsub.subscribe('notificaciones', function(data) {
    console.log('Notificación recibida:', data);
});

// Múltiples callbacks para el mismo canal
pubsub.subscribe('notificaciones', function(data) {
    console.log('Segundo handler:', data);
});
```
3. Modo Encadenado (fluent API):

```javascript
var notificaciones = pubsub.channel('notificaciones', this);

notificaciones.on('message', function(data) {
    console.log('Notificación:', data);
});

notificaciones.publish({ mensaje: 'Hola' });
```
4. Modo ExtJS Observable (para componentes):

```javascript
Ext.define('MyApp.controller.Notifications', {
extend: 'Ext.app.Controller',

    init: function() {
        this.pubsub = Ext.create('Vectura.PubSub', {
            url: 'ws://...'
        });
        
        this.pubsub.subscribe('user-updates', this.onUserUpdate, this);
        this.pubsub.subscribe('system-alerts', this.onSystemAlert, this);
    },
    
    onUserUpdate: function(data) {
        // Lógica específica para user-updates
        this.getView().updateUser(data);
    },
    
    onSystemAlert: function(data) {
        // Lógica específica para system-alerts
        Ext.Msg.alert('Alerta', data.message);
    }
});
```
