<?php

namespace CodeIT\ActiveRecord\Model;

use Zend\Db\Adapter\Adapter;
use CodeIT\Cache\Redis;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Select;
use CodeIT\Utils\Registry;

class ActiveSelect extends Select
{
    /**
     * Name of Class which will be returned
     * @var string
     */
    public $className;

    /**
     *
     * @var boolean
     */
    public $isOne = false;

    /**
     * Database adapter
     * @var Adapter
     */
    protected static $adapter;

    /**
     * Cache object
     * @var Redis
     */
    protected static $cache;

    /**
     * Returns cache provider
     * @return Redis
     */
    public static function cacheProvider()
    {
        if (!isset(static::$cache)) {
            static::$cache = Registry::get('sm')->get('cache');
        }
        return static::$cache;
    }

    /**
     * Returns database adapter
     * @return Adapter
     */
    public static function adapter()
    {
        if (!isset(static::$adapter)) {
            static::$adapter = Registry::get('sm')->get('dbAdapter');
        }
        return static::$adapter;
    }

    /**
     * Constructor
     * @param string $className
     * @param string|null $table
     */
    public function __construct($className, $table = null)
    {
        /* @var $className ActiveRecord */
        $this->className = $className;
        parent::__construct((is_null($table) ? $className::tableName() : $table));
    }

    /**
     * Returns array of instances or one instance. Depends on $isOne property
     *
     * @param $parentClass
     * @param $id
     * @param $paramName
     * @return array|object|false
     */
    public function getRelation($parentClass, $id, $paramName)
    {
        /* @var $className ActiveRecord */
        /* @var $parentClass ActiveRecord */
        $className = $this->className;
        $cacheKey = 'relation.'.$parentClass::tableName().'.'.$id.'.'.$paramName.'.'.($this->isOne ? 'one' : 'many').'.'.$className::tableName();
        if (!$resultIds = static::cacheProvider()->get($cacheKey)) {
            if ($this->isOne) {
                $this->limit(1);
            }
            $resultIds = [];
            $sql = new Sql(static::adapter());
            $request = $sql->prepareStatementForSqlObject($this)->execute();
            while ($row = $request->next()) {
                $resultIds[] = $row[$className::primaryKey()];
            }
            static::cacheProvider()->set($cacheKey, $resultIds);
        }
        if ($this->isOne) {
            if (count($resultIds) > 0 && $result = new $className($resultIds[0])) {
                return $result;
            } else {
                return false;
            }
        } else {
            $result = $this->getListOfRecords($resultIds);
            return $result;
        }
    }

    /**
     * Returns array of instances
     * @return array
     * @throws \Exception
     */
    public function getList()
    {
        try {
            $sql = new Sql(static::adapter());
            $className = $this->className;
            if ($this->columns === static::SQL_STAR) {
                $this->columns([$className::primaryKey()]);
            }
            $request = $sql->prepareStatementForSqlObject($this)->execute();
            $resultIds = [];
            while ($row = $request->next()) {
                $resultIds[] = $row[$className::primaryKey()];
            }
            $result = $this->getListOfRecords($resultIds);
            return $result;
        } catch (\Exception $e) {
            if (DEBUG) {
                $previousMessage = '';
                if ($e->getPrevious()) {
                    $previousMessage = ': '.$e->getPrevious()->getMessage();
                }
                throw new \Exception('SQL Error: '.$e->getMessage().$previousMessage);
            }
        }
    }

    /**
     * Returns array of instances
     * @return array
     * @throws \Exception
     */
    public function getArray()
    {
        try {
            $sql = new Sql(static::adapter());
            $className = $this->className;
            $request = $sql->prepareStatementForSqlObject($this)->execute();
            $result = [];
            while ($row = $request->next()) {
                $result[] = $row;
            }
            return $result;
        } catch (\Exception $e) {
            if (DEBUG) {
                $previousMessage = '';
                if ($e->getPrevious()) {
                    $previousMessage = ': '.$e->getPrevious()->getMessage();
                }
                throw new \Exception('SQL Error: '.$e->getMessage().$previousMessage);
            }
        }
    }

    /**
     * Returns array of ids
     *
     * @return array
     * @throws \Exception
     */
    public function getKeys()
    {
        try {
            $sql = new Sql(static::adapter());
            $className = $this->className;
            $this->columns([$className::primaryKey()]);
            $request = $sql->prepareStatementForSqlObject($this)->execute();
            $resultIds = [];
            while ($row = $request->next()) {
                $resultIds[] = $row[$className::primaryKey()];
            }
            return $resultIds;
        } catch (\Exception $e) {
            if (DEBUG) {
                $previousMessage = '';
                if ($e->getPrevious()) {
                    $previousMessage = ': '.$e->getPrevious()->getMessage();
                }
                throw new \Exception('SQL Error: '.$e->getMessage().$previousMessage);
            }
        }
    }

    public function joinRelation($relationName)
    {
        $className = $this->className;
        $class = new $className();
        /* @var $relation Relation */
        $relation = $class->{'relation'.ucfirst($relationName)}();
        $relatedClass = $relation->className;
        if (!empty($relation->linkByTable)) {
            throw new \ErrorException('Join by link table not implemented yet');
        } else {
            $on = [];
            foreach ($relation->link as $currentTableField => $relatedTableField) {
                $on[] = sprintf("%s.%s = %s.%s", $class::tableName(), $currentTableField, $relatedClass::tableName(), $relatedTableField);
            }
            $on = implode(' AND ', $on);
        }
        $this->columns([$class::primaryKey()]);
        $this->join($relatedClass::tableName(), $on, ['relatedPrimaryKey' => $relatedClass::primaryKey()], static::JOIN_LEFT);
        return $this;
    }

    /**
     * Returns one instance
     *
     * @return boolean|object
     * @throws \Exception
     */
    public function getOne()
    {
        try {
            $sql = new Sql(static::adapter());
            $className = $this->className;
            $this->columns([$className::primaryKey()]);
            $this->limit(1);
            $request = $sql->prepareStatementForSqlObject($this)->execute();
            $row = $request->current();
            if ($row && $result = new $className($row[$className::primaryKey()])) {
                return $result;
            } else {
                return null;
            }
        } catch (\Exception $e) {
            if (DEBUG) {
                $previousMessage = '';
                if ($e->getPrevious()) {
                    $previousMessage = ': '.$e->getPrevious()->getMessage();
                }
                throw new \Exception('SQL Error: '.$e->getMessage().$previousMessage);
            }
        }
    }

    /**
     * @return int
     * @throws \Exception
     */
    public function count()
    {
        try {
            $className = $this->className;
            $sql = new Sql(static::adapter());
            $this->columns([$className::primaryKey()]);
            $request = $sql->prepareStatementForSqlObject($this)->execute();
            return $request->count();
        } catch (\Exception $e) {
            if (DEBUG) {
                $previousMessage = '';
                if ($e->getPrevious()) {
                    $previousMessage = ': '.$e->getPrevious()->getMessage();
                }
                throw new \Exception('SQL Error: '.$e->getMessage().$previousMessage);
            }
        }
    }

    /**
     * Return list of instances
     * @param array $listIds
     * @return array
     * @throws \Exception
     */
    protected function getListOfRecords($listIds)
    {
        try {
            /* @var $className ActiveRecord */
            $className = $this->className;
            $requestKeys = [];
            $tableName = $className::tableName();
            foreach ($listIds as $id) {
                $requestKeys[] = 'record.'.$tableName.'.'.$id;
            }
            $resultData = static::cacheProvider()->mget($requestKeys);
            $result = [];
            if (is_array($resultData) && count($resultData) > 0) {
                foreach ($resultData as $cacheResult) {
                    if ($cacheResult != false) {
                        $result[$cacheResult[$className::primaryKey()]] = new $className();
                        $result[$cacheResult[$className::primaryKey()]]->setData($cacheResult);
                    }
                }
            }
            $stillAbsent = array_diff($listIds, array_keys($result));
            foreach ($stillAbsent as $id) {
                $result[$id] = new $className($id);
            }
            return $result;
        } catch (\Exception $e) {
            if (DEBUG) {
                $previousMessage = '';
                if ($e->getPrevious()) {
                    $previousMessage = ': '.$e->getPrevious()->getMessage();
                }
                throw new \Exception('SQL Error: '.$e->getMessage().$previousMessage);
            }
        }
    }
}
