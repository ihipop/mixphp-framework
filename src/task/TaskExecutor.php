<?php

namespace mix\task;

use mix\base\BaseObject;
use mix\helpers\ProcessHelper;

/**
 * 任务执行器类
 * @author 刘健 <coder.liu@qq.com>
 */
class TaskExecutor extends BaseObject
{

    // 守护程序类型
    const TYPE_DAEMON = 0;

    // 定时任务类型
    const TYPE_CRONTAB = 1;

    // 流水线模式
    const MODE_ASSEMBLY_LINE = 0;

    // 推送模式
    const MODE_PUSH = 1;

    // 程序名称
    public $name = '';

    // 执行类型
    public $type = self::TYPE_DAEMON;

    // 执行模式
    public $mode = self::MODE_ASSEMBLY_LINE;

    // 左进程数
    public $leftProcess = 0;

    // 中进程数
    public $centerProcess = 0;

    // 右进程数
    public $rightProcess = 0;

    // 任务超时时间 (秒)
    public $timeout = 5;

    // 队列名称
    public $queueName = '';

    // 左进程启动事件回调函数
    protected $_onLeftStart;

    // 中进程启动事件回调函数
    protected $_onCenterStart;

    // 右进程启动事件回调函数
    protected $_onRightStart;

    // 左进程集合
    protected $_leftProcesses = [];

    // 中进程集合
    protected $_centerProcesses = [];

    // 右进程集合
    protected $_rightProcesses = [];

    // 工作进程集合
    protected $_workers = [];

    // 共享内存表
    protected $_table;

    // 消息队列键名
    protected $_messageKey;

    // 初始化事件
    public function onInitialize()
    {
        parent::onInitialize(); // TODO: Change the autogenerated stub
        // 生成消息队列键名
        $this->_messageKey = crc32($this->queueName);
        // 创建内存表
        $table = new \Swoole\Table(4);
        $table->column('value', \Swoole\Table::TYPE_INT);
        $table->create();
        if ($this->type == self::TYPE_CRONTAB) {
            $table->set('crontabStatus', ['value' => LeftProcess::CRONTAB_STATUS_START]);
            $table->set('crontabCenterUnfinished', ['value' => $this->centerProcess]);
            $table->set('crontabRightUnfinished', ['value' => $this->rightProcess]);
        }
        if ($this->type == self::TYPE_DAEMON) {
            $table->set('daemonCenterUnfinished', ['value' => $this->centerProcess]);
            $table->set('daemonRightUnfinished', ['value' => $this->rightProcess]);
            $table->set('daemonImmediatelyExit', ['value' => 0]);
        }
        $this->_table = $table;
    }

    // 启动
    public function start()
    {
        // 修改进程标题
        ProcessHelper::setTitle("{$this->name} master");
        // 调整定时任务类型下的左进程数
        if ($this->type == self::TYPE_CRONTAB) {
            $this->leftProcess = 1;
        }
        // 调整推送模式下的右进程数
        if ($this->mode == self::MODE_PUSH) {
            $this->rightProcess = 0;
        }
        // 创建全部进程
        $this->createProcesses();
        // 重启退出的子进程
        \Swoole\Process::signal(SIGCHLD, function ($signal) {
            while ($ret = \swoole_process::wait(false)) {
                $this->rebootProcess($ret);
            }
        });
        // 接收信号，不消费完队列中的数据直接退出
        if ($this->type == self::TYPE_DAEMON) {
            $table = $this->_table;
            $mpid  = ProcessHelper::getPid();
            \Swoole\Process::signal(SIGUSR1, function ($signal) use ($table, $mpid) {
                $table->set('daemonImmediatelyExit', ['value' => 1]);
                ProcessHelper::kill($mpid);
            });
        }
    }

    // 注册Server的事件回调函数
    public function on($event, callable $callback)
    {
        switch ($event) {
            case 'LeftStart':
                $this->_onLeftStart = $callback;
                break;
            case 'CenterStart':
                $this->_onCenterStart = $callback;
                break;
            case 'RightStart':
                $this->_onRightStart = $callback;
                break;
        }
    }

    // 创建全部进程
    protected function createProcesses()
    {
        // 右中左，创建顺序不能更换
        for ($i = 0; $i < $this->rightProcess; $i++) {
            $this->createProcess('right', $i);
        }
        for ($i = 0; $i < $this->centerProcess; $i++) {
            $this->createProcess('center', $i);
        }
        for ($i = 0; $i < $this->leftProcess; $i++) {
            $this->createProcess('left', $i);
        }
    }

    // 创建进程
    protected function createProcess($processType, $index)
    {
        // 定义变量
        switch ($processType) {
            case 'right':
                $callback  = $this->_onRightStart;
                $taskClass = '\mix\task\RightProcess';
                $next      = null;
                $afterNext = null;
                break;
            case 'center':
                $callback  = $this->_onCenterStart;
                $taskClass = '\mix\task\CenterProcess';
                $next      = null;
                if (!empty($temp = $this->_rightProcesses)) {
                    $next = array_pop($temp);
                }
                $afterNext = null;
                break;
            case 'left':
                $callback  = $this->_onLeftStart;
                $taskClass = '\mix\task\LeftProcess';
                $next      = null;
                if (!empty($temp = $this->_centerProcesses)) {
                    $next = array_pop($temp);
                }
                $afterNext = null;
                if (!empty($temp = $this->_rightProcesses)) {
                    $afterNext = array_pop($temp);
                }
                break;
        }
        $type    = $this->type;
        $mode    = $this->mode;
        $mpid    = ProcessHelper::getPid();
        $timeout = $this->timeout;
        $table   = $this->_table;
        // 创建进程对象
        $process = new \Swoole\Process(function ($worker) use ($callback, $taskClass, $next, $afterNext, $type, $mode, $mpid, $timeout, $table, $processType, $index) {
            try {
                ProcessHelper::setTitle("{$this->name} {$processType} #{$index}");
                $taskProcess = new $taskClass([
                    'type'      => $type,
                    'mode'      => $mode,
                    'index'     => $index,
                    'mpid'      => $mpid,
                    'pid'       => $worker->pid,
                    'timeout'   => $timeout,
                    'current'   => $worker,
                    'next'      => $next,
                    'afterNext' => $afterNext,
                    'table'     => $table,
                ]);
                call_user_func($callback, $taskProcess);
            } catch (\Exception $e) {
                \Mix::app()->error->handleException($e);
            }
        }, false, false);
        // 开启进程消息队列
        switch ($processType) {
            case 'right':
                $process->useQueue($this->_messageKey + 2, 2);
                break;
            case 'center':
                $process->useQueue($this->_messageKey + 1, 2);
                break;
        }
        // 启动
        $pid = $process->start();
        // 保存实例
        $this->_workers[$pid] = [$processType, $index];
        switch ($processType) {
            case 'right':
                $this->_rightProcesses[$pid] = $process;
                break;
            case 'center':
                $this->_centerProcesses[$pid] = $process;
                break;
            case 'left':
                $this->_leftProcesses[$pid] = $process;
                break;
        }
    }

    // 重启进程
    protected function rebootProcess($ret)
    {
        $pid = $ret['pid'];
        if (isset($this->_workers[$pid])) {
            // 取出进程信息
            list($processType, $index) = $this->_workers[$pid];
            // 删除旧引用
            unset($this->_workers[$pid]);
            unset($this->_rightProcesses[$pid]);
            unset($this->_centerProcesses[$pid]);
            unset($this->_leftProcesses[$pid]);
            // 定时任务进程状态处理
            if ($this->type == self::TYPE_CRONTAB) {
                switch ($processType . ':' . $this->_table->get('crontabStatus', 'value')) {
                    case 'left:' . LeftProcess::CRONTAB_STATUS_START:
                        $this->_table->set('crontabStatus', ['value' => LeftProcess::CRONTAB_STATUS_FINISH]);
                        return;
                    case 'center:' . CenterProcess::CRONTAB_STATUS_FINISH:
                        if ($this->mode == self::MODE_PUSH) {
                            ProcessHelper::kill(ProcessHelper::getPid());
                        }
                        return;
                    case 'right:' . RightProcess::CRONTAB_STATUS_FINISH:
                        ProcessHelper::kill(ProcessHelper::getPid());
                        return;
                }
            }
            // 重建进程
            $this->createProcess($processType, $index);
            // 返回
            return;
        }
        throw new \mix\exceptions\TaskException('RebootProcess Error: no pid.');
    }

}
