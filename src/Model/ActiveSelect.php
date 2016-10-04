<?php

namespace CodeIT\ActiveRecord\Model;

class ActiveSelect extends \Zend\Db\Sql\Select {

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
	 * @var Zend\Db\Adapter\Adapter
	 */
	protected static $adapter;

	/**
	 * Cache object
	 * @var Application\Lib\Redis
	 */
	protected static $cache;

	/**
	 * Returns cache provider
	 * @return \Application\Lib\Redis
	 */
	public static function cacheProvider() {
		if (!isset(static::$cache)) {
			static::$cache = \CodeIT\Utils\Registry::get('sm')->get('cache');
		}
		return static::$cache;
	}

	/**
	 * Returns database adapter
	 * @return \Zend\Db\Adapter\Adapter
	 */
	public static function adapter() {
		if (!isset(static::$adapter)) {
			static::$adapter = \CodeIT\Utils\Registry::get('sm')->get('dbAdapter');
		}
		return static::$adapter;
	}

	/**
	 * Constructor
	 * @param string $className
	 */
	public function __construct($className, $table = null) {
		$this->className = $className;
		parent::__construct((is_null($table) ? $className::tableName() : $table));
	}

	/**
	 * Returns array of instances or one instance. Depends on $isOne property
	 * @return array|object
	 */
	public function getRelation($parentClass, $id, $paramName) {
		$className = $this->className;
		$cacheKey = 'relation.' . $parentClass::tableName() . '.' . $id . '.' . $paramName . '.' . ($this->isOne ? 'one' : 'many') . '.' . $className::tableName();
		if (!$resultIds = static::cacheProvider()->get($cacheKey)) {
			if ($this->isOne) {
				$this->limit(1);
			}
			$resultIds = [];
			$sql = new \Zend\Db\Sql\Sql(static::adapter());
			$request = $sql->prepareStatementForSqlObject($this)->execute();
			while ($row = $request->next()) {
				$resultIds[] = $row[$className::primaryKey()];
			}
			static::cacheProvider()->set($cacheKey, $resultIds);
		}
		if ($this->isOne) {
			if ($result = new $className($resultIds[0])) {
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
	 */
	public function getList() {
		try {
			$sql = new \Zend\Db\Sql\Sql(static::adapter());
			$className = $this->className;
			$this->columns([$className::primaryKey()]);
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
					$previousMessage = ': ' . $e->getPrevious()->getMessage();
				}
				throw new \Exception('SQL Error: ' . $e->getMessage() . $previousMessage . "<br>
					SQL Query was:<br><br>\n\n" . $sql->getSqlString($this->adapter->platform));
				//\Zend\Debug::dump($e);
			}
		}
	}

	/**
	 * Returns one instance
	 * @return boolean|object
	 */
	public function getOne() {
		try {
			$sql = new \Zend\Db\Sql\Sql(static::adapter());
			$className = $this->className;
			$this->columns([$className::primaryKey()]);
			$this->limit(1);
			$request = $sql->prepareStatementForSqlObject($this)->execute();
			$row = $request->current();
			if ($result = new $className($row[$className::primaryKey()])) {
				return $result;
			} else {
				return false;
			}
		} catch (\Exception $e) {
			if (DEBUG) {
				$previousMessage = '';
				if ($e->getPrevious()) {
					$previousMessage = ': ' . $e->getPrevious()->getMessage();
				}
				throw new \Exception('SQL Error: ' . $e->getMessage() . $previousMessage . "<br>
					SQL Query was:<br><br>\n\n" . $sql->getSqlString($this->adapter->platform));
				//\Zend\Debug::dump($e);
			}
		}
	}

	/**
	 * Return list of instances
	 * @param array $listIds
	 * @return array
	 */
	protected function getListOfRecords($listIds) {
		try {
			$className = $this->className;
			$requestKeys = [];
			$tableName = $className::tableName();
			foreach ($listIds as $id) {
				$requestKeys[] = 'record.' . $tableName . '.' . $id;
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
					$previousMessage = ': ' . $e->getPrevious()->getMessage();
				}
				throw new \Exception('SQL Error: ' . $e->getMessage() . $previousMessage . "<br>
					SQL Query was:<br><br>\n\n" . $sql->getSqlString($this->adapter->platform));
				//\Zend\Debug::dump($e);
			}
		}
	}

}
