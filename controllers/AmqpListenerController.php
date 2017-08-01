<?php
/**
 * @link https://github.com/rooooodik/yii2-amqp
 * @copyright Copyright (c) 2014 webtoucher
 * @license https://github.com/webtoucher/yii2-amqp/blob/master/LICENSE.md
 */

namespace rooooodik\amqp\controllers;

use yii\console\Exception;
use yii\helpers\Inflector;
use yii\helpers\Json;
use PhpAmqpLib\Message\AMQPMessage;
use rooooodik\amqp\components\Amqp;
use rooooodik\amqp\components\AmqpInterpreter;
use rooooodik\amqp\components\AmpqInterpreterInterface;
use rooooodik\commands\Controller;


/**
 * AMQP listener controller.
 *
 * @author Alexey Kuznetsov <mirakuru@webtoucher.ru>
 * @since 2.0
 */
class AmqpListenerController extends AmqpConsoleController
{
    /**
     * Interpreter classes for AMQP messages. This class will be used if interpreter class not set for exchange.
     *
     * @var array
     */
    public $interpreters = [];

    public function actionRun($routingKey = '#', $type = Amqp::TYPE_TOPIC, $timeout = 0)
    {
        try {
            $this->amqp->listen($this->exchange, $routingKey, [$this, 'callback'], $type, false, $timeout);
        } catch (\Exception $e) {
            if (!preg_match('/The connection timed out after [0-9]+ sec while awaiting incoming data/i' , $e->getMessage())) { // ignore timeout
                throw $e;
            }
        }
    }

    public function callback(AMQPMessage $msg)
    {
        $routingKey = $msg->delivery_info['routing_key'];
        $method = 'read' . Inflector::camelize($routingKey);

        if (!isset($this->interpreters[$this->exchange])) {
            $interpreter = $this;
        } elseif (class_exists($this->interpreters[$this->exchange])) {
            $interpreter = new $this->interpreters[$this->exchange];
            if (!$interpreter instanceof AmqpInterpreter) {
                throw new Exception(sprintf("Class '%s' is not correct interpreter class.", $this->interpreters[$this->exchange]));
            }
        } else {
            throw new Exception(sprintf("Interpreter class '%s' was not found.", $this->interpreters[$this->exchange]));
        }

        if (method_exists($interpreter, $method)) {
            $info = [
                'exchange' => $msg->get('exchange'),
                'routing_key' => $msg->get('routing_key'),
                'reply_to' => $msg->has('reply_to') ? $msg->get('reply_to') : null,
            ];
            if ($interpreter->$method(Json::decode($msg->body, true), $info)) {
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
            } else {
                $this->amqp->channel->basic_publish($msg, $msg->get('exchange'), $msg->get('routing_key'));
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
            }
        } else {
            if (!isset($this->interpreters[$this->exchange])) {
                $interpreter = new AmqpInterpreter();
            }
            $interpreter->log(
                sprintf("Unknown routing key '%s' for exchange '%s'.", $routingKey, $this->exchange),
                $interpreter::MESSAGE_ERROR
            );
            // debug the message
            $interpreter->log(
                print_r(Json::decode($msg->body, true), true),
                $interpreter::MESSAGE_INFO
            );
        }
    }
}
