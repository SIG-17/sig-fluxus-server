<?php

namespace SIG\Server\Protocol\Request\PubSub;

use SIG\Server\Exception\UnexpectedValueException;
use SIG\Server\Fluxus;
use SIG\Server\Protocol\Request\Action;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use SIG\Server\PubSub\PubSubManager;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class Publish extends Base implements RequestHandlerInterface
{

    protected(set) string $channel;
    protected(set) string|array|AbstractDescriptor $data = [] {
        set(string|array|AbstractDescriptor $data) {
            $this->data = $data instanceof AbstractDescriptor ? $data->toArray() : $data;
        }
    }

    public function __construct(
        ?array $values = [],
        Action $protocol = new PubSubDefinition()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['action'] = $protocol->get('publish');
        parent::__construct($values, $protocol);
    }

    /**
     * @throws UnexpectedValueException
     */
    public function handle(int $fd, Fluxus $server): void
    {
        if (empty($this->channel)) {
            $server->sendError($fd, 'Nombre de canal requerido');
            return;
        }
        /**
         * @var PubSubManager $protocol
         */
        $protocol = $server->getProtocolManager('pubsub');
        $channelInfo = $protocol->channels->get($this->channel);
        if ($channelInfo['requires_auth'] === 1) {
            if (!$server->isAuthenticated($fd)) {
                //throw new AuthenticationException('Channel is only for authenticated users');
                $server->sendError($fd, 'Channel is only for authenticated users');
                return;
            }
            if (!empty($channelInfo['requires_role']) && !in_array($channelInfo['requires_role'], $server->userRoles($fd))) {
                //throw new AuthenticationException('User does not have required role');
                $server->sendError($fd, 'User does not have required role');
                return;
            }
        }
        $message = [
            'type' => 'message',
            'channel' => $this->channel,
            'data' => $this->data,
            '_metadata' => [
                'timestamp' => time(),
                'publisher' => $fd,
                'origin_server' => $server->getServerId() // Identificar origen
            ]
        ];


        // Publicar localmente
        $localRecipients = $protocol->broadcastToChannel($this->channel, $message);

        // Publicar en Redis para otros servidores (solo si hay suscriptores en otros lados)
        if ($server->isRedisEnabled()) {
            try {
                $count = $server->redis()->publish("$server->redisChannelPrefix.$this->channel", json_encode($message));
                if ($count === false) {
                    $server->logger?->warning("No se pudo publicar en Redis: $this->channel");
                } else {
                    $server->logger?->debug("Mensaje publicado en Redis para canal: $this->channel ($count clientes)");
                }
            } catch (\Exception $e) {
                $server->logger?->error('Error publicando en Redis: ' . $e->getMessage());
            }
        }
        $protocol->channels->set($this->channel, [
            'name' => $this->channel,
            'auto_subscribe' => $channelInfo['auto_subscribe'],
            'subscriber_count' => $channelInfo['subscriber_count'],
            'created_at' => $channelInfo['created_at'],
            'last_message_at' => time(),
            'last_message_fd' => $fd,
            'requires_auth' => $channelInfo['requires_auth'],
            'requires_role' => $channelInfo['requires_role']
        ]);

        $server->sendSuccess($fd, "Mensaje publicado en: $this->channel (local: $localRecipients clientes)");
    }
}