<?php

namespace Mix\Core\Container;

use Mix\Core\Bean\Bean;
use Mix\Core\Component\Component;
use Mix\Core\Coroutine\Coroutine;
use Mix\Core\Bean\BeanObject;
use Psr\Container\ContainerInterface;

/**
 * Class ContainerManager
 * @package Mix\Core\Container
 * @author LIUJIAN <coder.keda@gmail.com>
 */
class ContainerManager extends BeanObject implements ContainerInterface
{

    /**
     * 组件配置
     * @var array
     */
    public $config = [];

    /**
     * 容器集合
     * @var array
     */
    protected $_containers = [];

    /**
     * 获取容器
     * @param $name
     * @return ComponentInterface
     */
    public function get($name)
    {
        $tid = $this->getTid($name);
        if (!isset($this->_containers[$tid])) {
            $this->_containers[$tid] = new Container([
                'manager' => $this,
            ]);
        }
        return $this->_containers[$tid]->get($name);
    }

    /**
     * 判断容器是否存在
     * @param $name
     * @return bool
     */
    public function has($name)
    {
        $tid = $this->getTid($name);
        if (!isset($this->_containers[$tid])) {
            return false;
        }
        return $this->_containers[$tid]->has($name);
    }

    /**
     * 移除容器
     * @param $tid 顶部协程id
     */
    public function delete($tid)
    {
        $this->_containers[$tid] = null;
        unset($this->_containers[$tid]);
    }

    /**
     * 获取顶部协程id
     * @param $name
     * @return int
     */
    protected function getTid($name)
    {
        $tid  = Coroutine::tid();
        $mode = $this->getCoroutineMode($name);
        if ($mode === false) {
            $tid = -2;
        }
        if ($mode == Component::COROUTINE_MODE_REFERENCE) {
            $tid = -1;
        }
        return $tid;
    }

    /**
     * 获取协程模式
     * @param $name
     * @return bool|int
     */
    protected function getCoroutineMode($name)
    {
        try {
            $bean  = Bean::config($this->config[$name]['ref']);
            $class = $bean['class'];
            return $class::$coroutineMode ?? false;
        } catch (\Throwable $e) {
            return false;
        }
    }

}