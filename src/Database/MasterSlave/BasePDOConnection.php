<?php

namespace Mix\Database\MasterSlave;

/**
 * Class BasePDOConnection
 * @package Mix\Database\MasterSlave
 * @author LIUJIAN <coder.keda@gmail.com>
 */
class BasePDOConnection extends \Mix\Database\BasePDOConnection
{

    /**
     * 主服务器组
     * @var array
     */
    public $masters = [];

    /**
     * 配置主服务器
     * @var array
     */
    public $masterConfig = [];

    /**
     * 从服务器组
     * @var array
     */
    public $slaves = [];

    /**
     * 配置从服务器
     * @var array
     */
    public $slaveConfig = [];

    /**
     * PDO Master
     * @var \PDO
     */
    protected $_pdoMaster;

    /**
     * PDO Slave
     * @var \PDO
     */
    protected $_pdoSlave;

    /**
     * 关闭连接
     */
    public function disconnect()
    {
        parent::disconnect();
        $this->_pdoMaster = null;
        $this->_pdoSlave  = null;
    }

    /**
     * 使用主库
     */
    public function useMaster()
    {
        if (!isset($this->_pdoMaster)) {
            $this->dsn      = $this->masters[array_rand($this->masters)];
            $this->username = $this->masterConfig['username'];
            $this->password = $this->masterConfig['password'];
            parent::connect();
            $this->_pdoMaster = $this->_pdo;
        } else {
            $this->_pdo = $this->_pdoMaster;
        }
    }

    /**
     * 使用从库
     */
    public function useSlave()
    {
        if (!isset($this->_pdoSlave)) {
            $this->dsn      = $this->slaves[array_rand($this->slaves)];
            $this->username = $this->slaveConfig['username'];
            $this->password = $this->slaveConfig['password'];
            parent::connect();
            $this->_pdoSlave = $this->_pdo;
        } else {
            $this->_pdo = $this->_pdoSlave;
        }
    }

    /**
     * 返回结果集
     * @return \PDOStatement
     */
    public function query()
    {
        return $this->call(__FUNCTION__);
    }

    /**
     * 返回一行
     * @return mixed
     */
    public function queryOne()
    {
        return $this->call(__FUNCTION__);
    }

    /**
     * 返回多行
     * @return mixed
     */
    public function queryAll()
    {
        return $this->call(__FUNCTION__);
    }

    /**
     * 返回一列 (第一列)
     * @param int $columnNumber
     * @return array|mixed
     */
    public function queryColumn($columnNumber = 0)
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * 返回一个标量值
     * @return mixed
     */
    public function queryScalar()
    {
        return $this->call(__FUNCTION__);
    }

    /**
     * 执行SQL语句
     * @return bool
     */
    public function execute()
    {
        return $this->call(__FUNCTION__);
    }

    /**
     * 开始事务
     * @return bool
     */
    public function beginTransaction()
    {
        return $this->call(__FUNCTION__);
    }

    /**
     * 执行方法
     * @param $name
     * @param array $arguments
     * @return mixed
     */
    protected function call($name, $arguments = [])
    {
        switch ($name) {
            case 'query':
            case 'queryOne':
            case 'queryAll':
            case 'queryColumn':
            case 'queryScalar':
                if ($this->inTransaction()) {
                    $this->useMaster();
                } else {
                    $this->useSlave();
                }
                break;
            case 'execute':
                $this->useMaster();
                break;
            case 'beginTransaction':
                $this->useMaster();
                break;
        }
        return call_user_func_array("parent::{$name}", $arguments);
    }

    /**
     * 检查是否在一个事务内
     * @return bool
     */
    protected function inTransaction()
    {
        // 检查是否有Master连接，且在一个事务内
        if (isset($this->_pdoMaster) && $this->_pdoMaster->inTransaction()) {
            return true;
        }
        return false;
    }

}
