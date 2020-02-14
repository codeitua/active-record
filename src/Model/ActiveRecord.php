<?php

namespace CodeIT\ActiveRecord\Model;

use Zend\Db\Adapter\Adapter;
use Zend\Db\TableGateway\TableGateway;
use CodeIT\Utils\Registry;
use CodeIT\Cache\Redis;

/**
 * ActiveRecord is the base class for classes representing relational data in terms of objects.
 *
 * Active Record implements the [Active Record design pattern]( http://en.wikipedia.org/wiki/Active_record ).
 * The premise behind Active Record is that an individual [[ActiveRecord]] object is associated with a specific
 * row in a database table. The object's attributes are mapped to the columns of the corresponding table.
 * Referencing an Active Record attribute is equivalent to accessing the corresponding table column for that record.
 */
class ActiveRecord
{
    /**
     * Field name for relation table in ordered relation tables
     */
    const ORDERING_FIELD = 'ordering';

    /**
     * Cache object
     * @var Redis
     */
    protected static $cache;

    /**
     * Database adapter
     * @var Adapter
     */
    protected static $adapter;

    /**
     * Zend TableGateways array
     * @var TableGateway[]
     */
    protected static $tableGateways;

    /**
     * list of pending functions, which will be ran after save
     * @var array
     */
    protected $pendingData = [];

    /**
     * List of related instances
     * @var array 
     */
    protected $_related = [];

    /**
     * Storage where kept all primary keys of databases
     * @var array
     */
    protected static $primaryKeys = [];

    /**
     * Storage where kept all structures of databases
     * @var array
     */
    protected static $structures = [];

    /**
     * Storage where kept all default values from databases
     * @var array
     */
    protected static $default = [];

    /**
     * Storage where kept all types from databases
     * @var array
     */
    protected static $types = [];

    /**
     * Current data if property wasn't declared
     * @var array
     */
    protected $_storage = [];

    /**
     * Registered value for ordered relations
     * @var int
     */
    protected $__ordering = null;

    /**
     * Return current table name from class name (first letter changed to small)
     * If you want to use Another table name - redeclare it
     * @return string
     */
    public static function tableName()
    {
        $tableData = explode('\\', static::className());
        return lcfirst($tableData[count($tableData) - 1]);
    }

    /**
     * Getter
     * @param mixed $param
     * @return mixed
     */
    public function __get($param)
    {
        if (isset($this->_storage[$param])) {
            return $this->_storage[$param];
        } elseif (isset($this->_related[$param])) {
            return $this->_related[$param];
        } elseif (method_exists($this, 'relation'.ucfirst($param))) {
            return $this->_related[$param] = $this->getRelation($this->{'relation'.ucfirst($param)}());
        } elseif (method_exists($this, 'get'.ucfirst($param))) {
            $result = $this->{'get'.ucfirst($param)}();
            if ($result instanceof ActiveSelect) {
                return $this->_related[$param] = $result->getRelation(static::className(), $this->{static::primaryKey()}, $param);
            } else {
                return $this->_storage[$param] = $result;
            }
        }

        return null;
    }

    /**
     * Setter
     * @param string $param
     * @param mixed $value
     */
    public function __set($param, $value)
    {
        if (method_exists($this, 'relation'.ucfirst($param))) {
            $this->setRelation($this->{'relation'.ucfirst($param)}(), $value);
        } elseif (method_exists($this, 'set'.ucfirst($param))) {
            $this->{'set'.ucfirst($param)}($value);
        } elseif (in_array($param, static::structure())) {
            $this->_storage[$param] = $value;
        } elseif (isset($this->_related[$param])) {
            $this->_related[$param] = $value;
        }
    }

    /**
     *
     * @param string $param
     * @return boolean
     */
    public function __isset($param)
    {
        if (isset($this->_storage[$param])) {
            return true;
        } elseif (isset($this->_related[$param])) {
            return true;
        } elseif ((method_exists($this, 'get'.ucfirst($param)) || method_exists($this, 'relation'.ucfirst($param))) && $this->__get($param)) {
            return true;
        } else {
            return false;
        }
    }

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
     * @return \Zend\Db\Adapter\Adapter
     */
    public static function adapter()
    {
        if (!isset(static::$adapter)) {
            static::$adapter = Registry::get('sm')->get('dbAdapter');
        }
        return static::$adapter;
    }

    /**
     * returns TableGateway
     * @return \Zend\Db\TableGateway\TableGateway
     */
    public static function tableGateway()
    {
        if (!isset(static::$tableGateways[static::tableName()])) {
            static::$tableGateways[static::tableName()] = new TableGateway(static::tableName(), static::adapter());
        }
        return static::$tableGateways[static::tableName()];
    }

    /**
     * returns primary key field name of current table
     * @return string
     */
    public static function primaryKey()
    {
        if (!isset(static::$primaryKeys[static::className()])) {
            static::structure();
        }
        return static::$primaryKeys[static::className()];
    }

    /**
     * returns defaults values
     * @return array
     */
    public static function defaults($param = null)
    {
        if (!isset(static::$default[static::className()])) {
            static::structure();
        }
        if (is_null($param)) {
            return static::$default[static::className()];
        }
        if (in_array($param, static::structure())) {
            return static::$default[static::className()][$param];
        }
    }

    public static function types($param = null)
    {
        if (!isset(static::$types[static::className()])) {
            static::structure();
        }
        if (is_null($param)) {
            return static::$types[static::className()];
        }
        if (in_array($param, static::structure())) {
            return static::$types[static::className()][$param];
        }
    }

    /**
     * returns list of fields of current table
     * @return array
     */
    public static function structure()
    {
        $className = static::className();
        if (!isset(static::$structures[$className])) {
            if (!(
                (static::$structures[$className] = static::cacheProvider()->get('structure.'.static::tableName())) &&
                (static::$default[$className] = static::cacheProvider()->get('default.'.static::tableName())) &&
                (static::$types[$className] = static::cacheProvider()->get('types.'.static::tableName())) &&
                (static::$primaryKeys[$className] = static::cacheProvider()->get('primaryKey.'.static::tableName()))
                )) {
                $structureRequest = static::adapter()->query('SHOW COLUMNS FROM `'.static::tableName().'`')->execute();
                static::$structures[$className] = [];
                while ($row = $structureRequest->next()) {
                    static::$structures[$className][] = $row['Field'];
                    static::$default[$className][$row['Field']] = $row['Default'];
                    static::$types[$className][$row['Field']] = static::prepareType($row['Type'], $row['Null']);
                    if ($row['Key'] == 'PRI') {
                        static::$primaryKeys[$className] = $row['Field'];
                    }
                }
                static::cacheProvider()->set('structure.'.static::tableName(), static::$structures[$className]);
                static::cacheProvider()->set('default.'.static::tableName(), static::$default[$className]);
                static::cacheProvider()->set('types.'.static::tableName(), static::$types[$className]);
                static::cacheProvider()->set('primaryKey.'.static::tableName(), static::$primaryKeys[$className]);
            }
        }
        return static::$structures[$className];
    }

    public static function prepareType($type, $null)
    {
        $result = [];
        $parsed = preg_match_all("/(.*)\\((.*)\\)(.*)/", $type, $params);
        $params = array_column($params, 0);
        if ($parsed) {
            switch (true) {
                case ($params[1] === 'tinyint' && $params[2] === '1'):
                    $result = [
                        'type' => 'boolean',
                        'length' => $params[2],
                    ];
                    break;
                case ($params[1] === 'enum' || $params[1] === 'set'):
                    $variants = explode(',', $params[2]);
                    $variants = array_map('trim', $variants, array_fill(0, count($variants), "'"));
                    $result = [
                        'type' => $params[1],
                        'length' => count($variants),
                        'variants' => $variants,
                    ];
                    break;
                default:
                    $result = [
                        'type' => $params[1],
                        'length' => $params[2],
                    ];
                    break;
            }
            if (!empty($params[3])) {
                $additionalParams = explode(' ', trim($params[3]));
                $result['unsigned'] = in_array('unsigned', $additionalParams);
            }
        } else {
            $result = [
                'type' => $type,
            ];
        }
        $result['null'] = ($null === 'YES');
        return $result;
    }

    /**
     * Returns record from database/cache
     * @param int $id
     * @return \ArrayObject
     *
     * @throws \Exception
     */
    public static function get($id)
    {
        if (!$result = static::cacheProvider()->get('record.'.static::tableName().'.'.$id)) {
            try {
                $result = static::tableGateway()->select([static::primaryKey() => $id])->current();
            } catch (\Exception $e) {
                $previousMessage = '';
                if ($e->getPrevious()) {
                    $previousMessage = ': '.$e->getPrevious()->getMessage();
                }
                throw new \Exception('SQL Error: '.$e->getMessage().$previousMessage);
            }
            if (empty($result)) {
                throw new \Exception(self::className().' '.$id.' not found');
            }
            static::cacheProvider()->set('record.'.static::tableName().'.'.$id, $result);
        }
        return $result;
    }

    /**
     * Constructor
     * @param int|null $id
     */
    public function __construct($id = null)
    {
        if (!is_null($id)) {
            if ($result = static::get($id)) {
                $this->setData(static::mapDataFromSQL($result));
            }
        } else {
            $this->setData(static::defaults());
        }
    }

    /**
     * Returns data from object by selected fields as array.
     * If $listOfFields == null returns all fields
     * @param array|null $listOfFields
     * @param bool $keysAsIndexes
     * @return array
     */
    public function extract($listOfFields = null, $keysAsIndexes = true)
    {
        if (!is_array($listOfFields)) {
            $listOfFields = static::structure();
        }
        $result = [];
        foreach ($listOfFields as $key => $field) {
            if (is_array($field)) {
                $result[$key] = $this->extractRelated($key, $field, $keysAsIndexes);
            } elseif ($this->{$field} && isset($this->_related[$field])) {
                $result[$field] = $this->extractRelated($field, $keysAsIndexes, $keysAsIndexes);
            } elseif (($pos = mb_stripos($field, '.')) > -1) {
                list($related, $relatedField) = explode('.', $field, 2);
                if ($this->{$related} && isset($this->_related[$related])) {
                    $result[$field] = $this->extractRelated($related, [$relatedField], false)[$relatedField];
                } else {
                    return null;
                }
            } else {
                $result[$field] = $this->{$field};
            }
        }
        return $result;
    }

    /**
     * Extract related model for extract method
     * @param string $field
     * @param array $listOfFields
     * @param bool $keysAsIndexes
     * @return mixed
     */
    private function extractRelated($field, $listOfFields = null, $keysAsIndexes = true)
    {
        if (!empty($this->{$field}) && is_object($this->{$field}) && method_exists($this->{$field}, 'extract')) {
            $result = $this->{$field}->extract($listOfFields, $keysAsIndexes);
        } elseif (is_array($this->{$field})) {
            $result = [];
            /* @var $value ActiveRecord */
            foreach ($this->{$field} as $key => $value) {
                if ($keysAsIndexes) {
                    $result[$key] = $value->extract($listOfFields, $keysAsIndexes);
                } else {
                    $result[] = $value->extract($listOfFields, $keysAsIndexes);
                }
            }
        } else {
            $result = $this->{$field};
        }
        return $result;
    }

    /**
     * Saves current state of object
     */
    public function save()
    {
        try {
            $values = static::mapDataToSQL($this->extract());
            if (empty($this->{static::primaryKey()}) && static::tableGateway()->insert($values)) {
                $this->setData(static::mapDataFromSQL([static::primaryKey() => static::tableGateway()->getLastInsertValue()]));
                $this->clearRelationCache();
            } else {
                static::tableGateway()->update($values, static::primaryKey().' = \''.$this->{static::primaryKey()}.'\'');
            }
            $this->clearCache();
            $this->runPending();
            static::get($this->{static::primaryKey()});
        } catch (\Exception $e) {
            $previousMessage = '';
            if ($e->getPrevious()) {
                $previousMessage = ': '.$e->getPrevious()->getMessage();
            }
            throw new \Exception('SQL Error: '.$e->getMessage().$previousMessage);
        }
    }

    /**
     * Updates object with data from incoming array
     * @param array $array
     */
    public function setData($array)
    {
        foreach ($array as $key => $value) {
            if (method_exists($this, 'relation'.ucfirst($key))) {
                $this->setRelation($this->{'relation'.ucfirst($key)}(), $value);
            } elseif (method_exists($this, 'set'.ucfirst($key))) {
                $this->{'set'.ucfirst($key)}($value);
            } elseif (in_array($key, static::structure())) {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * Declares a `has-many` relation.
     * The declaration is returned in terms of a relational [[ActiveSelect]] instance
     * through which the related record can be queried and retrieved back.
     *
     * A `has-many` relation means that there are multiple related records matching
     * the criteria set by this relation, e.g., a customer has many orders.
     *
     * For example, to declare the `orders` relation for `Customer` class, we can write
     * the following code in the `Customer` class:
     *
     * ```php
     * public function getOrders()
     * {
     *     return $this->hasMany(Order::className(), ['customer_id' => 'id']);
     * }
     * ```
     *
     * Note that in the above, the 'customer_id' key in the `$link` parameter refers to
     * an attribute name in the related class `Order`, while the 'id' value refers to
     * an attribute name in the current AR class.
     *
     * Call methods declared in [[ActiveSelect]] to further customize the relation.
     *
     * @param string $className the class name of the related record
     * @param array $link the primary-foreign key constraint. The keys of the array refer to
     * the attributes of the record associated with the `$class` model, while the values of the
     * array refer to the corresponding attributes in returned class.
     * @param string|null $relationTableName name of the junction table
     * @param array $linkByTable array keys of array points on fields where stored links to
     * current class table and values to links on needed table
     * @return ActiveSelect
     */
    public function hasMany($className, $link, $relationTableName = null, $linkByTable = null)
    {
        $relation = new Relation($className, $link, true, $relationTableName, $linkByTable, lcfirst(substr(debug_backtrace()[1]['function'], 8)), $order);
        return $this->buildRelatedSelect($relation);
    }

    /**
     * Declares a `has-one` relation.
     * The declaration is returned in terms of a relational [[ActiveSelect]] instance
     * through which the related record can be queried and retrieved back.
     *
     * A `has-one` relation means that there is at most one related record matching
     * the criteria set by this relation, e.g., a customer has one country.
     *
     * For example, to declare the `country` relation for `Customer` class, we can write
     * the following code in the `Customer` class:
     *
     * ```php
     * public function getCountry()
     * {
     *     return $this->hasOne(Country::className(), ['id' => 'country_id']);
     * }
     * ```
     *
     * Note that in the above, the 'id' key in the `$link` parameter refers to an attribute name
     * in the related class `Country`, while the 'country_id' value refers to an attribute name
     * in the current AR class.
     *
     * Call methods declared in [[ActiveSelect]] to further customize the relation.
     *
     * @param string $className the class name of the related record
     * @param array $link the primary-foreign key constraint. The keys of the array refer to
     * the attributes of the record associated with the `$class` model, while the values of the
     * array refer to the corresponding attributes in returned class.
     * @param string|null $relationTableName name of the junction table
     * @param array $linkByTable array keys of array points on fields where stored links to
     * current class table and values to links on needed table
     * @return ActiveSelect
     */
    public function hasOne($className, $link, $relationTableName = null, $linkByTable = null, $order = null)
    {
        $relation = new Relation($className, $link, false, $relationTableName, $linkByTable, lcfirst(substr(debug_backtrace()[1]['function'], 8)), $order);
        return $this->buildRelatedSelect($relation);
    }

    /**
     * Builds query for ->hasOne and ->hasMany methods
     * 
     * @param string $className the class name of the related record
     * @param array $link the primary-foreign key constraint. The keys of the array refer to
     * the attributes of the record associated with the `$class` model, while the values of the
     * array refer to the corresponding attributes in returned class.
     * @param boolean $isOne if set true return object instead of array of objects
     * @param string|null $relationTableName name of the junction table
     * @param array $linkByTable array keys of array points on fields where stored links to
     * current class table and values to links on needed table
     * @return ActiveSelect
     */
    protected function buildRelatedSelect(Relation $relation)
    {
        /* @var $className ActiveRecord */
        $columns = [];
        if (!is_null($relation->relationTableName) && is_array($relation->linkByTable)) {
            $select = new ActiveSelect($relation->className, [$relation->className::tableName() => $relation->relationTableName]);
            $where = [];
            foreach ($relation->linkByTable as $currentTableKeyInRelationTable => $linkedTableKeyInRelationTable) {
                foreach ($relation->link as $currentTableKey => $linkedTableKey) {
                    $where[$currentTableKeyInRelationTable] = $this->{$currentTableKey};
                    $columns = [$linkedTableKey => $linkedTableKeyInRelationTable];
                }
            }
            $select->where($where);
        } else {
            $select = new ActiveSelect($relation->className);
            $columns = [$relation->className::primaryKey()];
            $where = [];
            foreach ($relation->link as $currentTableKey => $linkedTableKey) {
                $where[$relation->className::tableName().'.'.$linkedTableKey] = $this->{$currentTableKey};
            }
            $select->where($where);
        }
        if ($relation->order === true) {
            $select->order(static::ORDERING_FIELD.' asc');
            $columns['__ordering'] = static::ORDERING_FIELD;
        } elseif (!empty($relation->order)) {
            $select->order($relation->order);
        }
        $select->columns($columns);
        $select->isOne = !$relation->hasMany;
        return $select;
    }

    /**
     * Return name of current class
     * @return string
     */
    public static function className()
    {
        return get_called_class();
    }

    /**
     * Return ActiveSelect of current class
     * @return ActiveSelect
     */
    public static function query()
    {
        $select = new ActiveSelect(static::className());
        return $select;
    }

    /**
     * Deletes record from database
     */
    public function delete()
    {
        try {
            static::tableGateway()->delete([static::primaryKey() => $this->{static::primaryKey()}]);
            $this->clearCache();
            $this->clearRelation();
        } catch (\Exception $e) {
            $previousMessage = '';
            if ($e->getPrevious()) {
                $previousMessage = ': '.$e->getPrevious()->getMessage();
            }
            throw new \Exception('SQL Error: '.$e->getMessage().$previousMessage);
        }
    }

    /**
     * Clear cache of record
     */
    public function clearCache()
    {
        static::cacheProvider()->deleteCache('record.'.static::tableName().'.'.$this->{static::primaryKey()});
    }

    /**
     * clears cache of relation
     */
    public function clearRelation()
    {
        $this->clearRelationCache();
        foreach ($this->listOfRelations() as $param) {
            if (is_object($this->{$param}) && method_exists($this->{$param}, 'clearRelationCache')) {
                $this->{$param}->clearRelationCache();
            }
        }
    }

    public function listOfRelations()
    {
        $result = [];
        foreach (get_class_methods(static::className()) as $method) {
            if (substr($method, 0, 8) === 'relation' && !in_array($method, ['relationMany', 'relationOne', 'relation'], true)) {
                $result[] = lcfirst(substr($method, 8));
            }
        }
        return $result;
    }

    public function clearRelationCache()
    {
        static::cacheProvider()->deleteByMask('relation.'.static::tableName().'.'.$this->{static::primaryKey()}.'.');
    }

    /**
     * Add some method to the charge for executing after save
     * @param string $methodName
     * @param array $attributes
     */
    public function addPending($methodName, $attributes = [])
    {
        if (method_exists($this, $methodName)) {
            $this->pendingData[] = ['method' => $methodName, 'attributes' => $attributes];
        }
    }

    /**
     * Runs methods from charge
     */
    public function runPending()
    {
        foreach ($this->pendingData as $key => $pending) {
            unset($this->pendingData[$key]);
            call_user_func_array([$this, $pending['method']], $pending['attributes']);
        }
        $this->pendingData = [];
    }

    /**
     * Setter for "has many" relation 
     * runs methods hasManySetterWithoutRelation or hasManySetterWithRelation
     * if object hasn't id setter stands in charge to execution after save
     * 
     * @param string $className
     * @param array $link
     * @param array $data
     * @param string $param
     * @param string $relationTableName
     * @param array $linkByTable
     */
    public function hasManySetter(Relation $relation, array $data)
    {
        /* @var $className ActiveRecord */
        if (!$this->{static::primaryKey()} > 0) {
            $this->addPending('hasManySetter', func_get_args());
            return;
        }
        if (is_null($relation->relationTableName) || is_null($relation->linkByTable)) {
            $this->hasManySetterWithoutRelation($relation, $data);
        } else {
            $this->hasManySetterWithRelation($relation, $data);
        }
        static::cacheProvider()->deleteCache('relation.'.static::tableName().'.'.$this->{static::primaryKey()}.'.'.$relation->paramName.'.many.'.$relation->className::tableName());
    }

    public function hasOneSetter(Relation $relation, $data)
    {
        if (!empty($relation->relationTableName) && !empty($relation->linkByTable)) {
            $this->hasOneSetterWithRelation($relation, $data);
        } else {
            $this->hasOneSetterWithoutRelation($relation, $data);
        }
    }

    protected function hasOneSetterWithoutRelation(Relation $relation, $dataItem)
    {
        $currentTableField = array_keys($relation->link)[0];
        $linkedTableField = $relation->link[$currentTableField];
        if ($currentTableField === static::primaryKey() && !$this->{static::primaryKey()} > 0) {
            $this->addPending('hasOneSetter', func_get_args());
            return;
        }
        //@todo: need to remove relation if null
        if (is_null($dataItem)) {
            return;
        }
        if (!is_array($dataItem)) {
            $dataItem = (array) $dataItem;
        }
        if ($currentTableField === static::primaryKey()) {
            $dataItem[$linkedTableField] = $this->{$currentTableField};
        }
        if (isset($this->{$relation->paramName})) {
            $this->{$relation->paramName}->setData($dataItem);
            $this->{$relation->paramName}->save();
        } else {
            $newItem = new $relation->className();
            unset($dataItem[$relation->className::primaryKey()]);
            $newItem->setData($dataItem);
            $newItem->save();
            $this->_related[$relation->paramName] = $newItem;
        }
        if ($currentTableField !== static::primaryKey()) {
            $this->{$currentTableField} = $this->_related[$relation->paramName]->{$linkedTableField};
        }
    }

    protected function hasOneSetterWithRelation(Relation $relation, $data)
    {
        $currentTableField = array_keys($relation->link)[0];
        $linkedTableField = $relation->link[$currentTableField];
        $currentTableFieldInRelation = array_keys($relation->linkByTable)[0];
        $linkedTableFieldInRelation = $relation->linkByTable[$currentTableFieldInRelation];
        $tableGateway = new TableGateway($relation->relationTableName, static::adapter());
        $tableGateway->insert([$currentTableFieldInRelation => $this->{static::primaryKey()}, $linkedTableFieldInRelation => $data[$linkedTableField]]);
        unset($this->_related[$relation->paramName]);
    }

    public function relationMany($className, $link, $relationTableName = null, $linkByTable = null, $order = null)
    {
        return $this->relation($className, $link, true, $relationTableName, $linkByTable, lcfirst(substr(debug_backtrace()[1]['function'], 8)), $order);
    }

    public function relationOne($className, $link, $relationTableName = null, $linkByTable = null, $order = null)
    {
        return $this->relation($className, $link, false, $relationTableName, $linkByTable, lcfirst(substr(debug_backtrace()[1]['function'], 8)), $order);
    }

    /**
     * Returns relation object
     * @param type $className
     * @param type $link
     * @param type $hasMany
     * @param type $relationTableName
     * @param type $linkByTable
     * @param type $paramName
     * @return \CodeIT\ActiveRecord\Model\Relation
     */
    public function relation($className, $link, $hasMany = false, $relationTableName = null, $linkByTable = null, $paramName = null, $order = null)
    {
        $relation = new Relation($className, $link, $hasMany, $relationTableName, $linkByTable, (!empty($paramName) ? $paramName : lcfirst(substr(debug_backtrace()[1]['function'], 8))), $order);
        return $relation;
    }

    /**
     * @param $relation Relation
     * @return array|bool|object
     */
    protected function getRelation(Relation $relation)
    {
        $activeSelect = $this->buildRelatedSelect($relation);
        if ($relation->hasMany) {
            $result = $activeSelect->getList();
        } else {
            $result = $activeSelect->getOne();
        }
        return $result;
    }

    protected function setRelation($relation, $data)
    {
        if ($relation->hasMany) {
            $this->hasManySetter($relation, $data);
        } else {
            $this->hasOneSetter($relation, $data);
        }
    }

    /**
     * Setter for "has many" relation without relation table
     * 
     * @param string $className
     * @param array $link
     * @param array $data
     * @param string $param
     */
    protected function hasManySetterWithoutRelation(Relation $relation, array $data)
    {
        /* @var $className ActiveRecord */
        $currentTableField = array_keys($relation->link)[0];
        $linkedTableField = $relation->link[$currentTableField];
        foreach ($data as $key => $dataItem) {
            if (!is_array($dataItem)) {
                $dataItem = (array) $dataItem;
            }
            $dataItem[$linkedTableField] = $this->{$currentTableField};
            $itemId = ((isset($dataItem[$relation->className::primaryKey()]) && (int) $dataItem[$relation->className::primaryKey()] > 0) ? $dataItem[$relation->className::primaryKey()] : 0);
            $this->{$relation->paramName};
            if ($itemId > 0 && isset($this->{$relation->paramName}[$itemId])) {
                $this->{$relation->paramName}[$itemId]->setData($dataItem);
                $this->{$relation->paramName}[$itemId]->save();
            } else {
                $newItem = new $relation->className();
                unset($dataItem[$relation->className::primaryKey()]);
                $newItem->setData($dataItem);
                $newItem->save();
                $data[$key][$relation->className::primaryKey()] = $newItem->{$relation->className::primaryKey()};
                $this->_related[$relation->paramName][$newItem->{$relation->className::primaryKey()}] = $newItem;
            }
        }
        if (isset($this->{$relation->paramName})) {
            $toDeleteIds = array_diff(array_keys($this->_related[$relation->paramName]), array_column($data, $relation->className::primaryKey()));
            foreach ($toDeleteIds as $id) {
                $this->{$relation->paramName}[$id]->delete();
                unset($this->_related[$relation->paramName][$id]);
            }
        }
    }

    /**
     * Setter for "has many" relation with relation table
     *
     * @param array $link
     * @param array $data
     * @param string $param
     * @param string $relationTableName
     * @param array $linkByTable
     */
    protected function hasManySetterWithRelation(Relation $relation, $data)
    {
        $currentTableField = array_keys($relation->link)[0];
        $linkedTableField = $relation->link[$currentTableField];
        $currentTableFieldInRelation = array_keys($relation->linkByTable)[0];
        $linkedTableFieldInRelation = $relation->linkByTable[$currentTableFieldInRelation];
        $tableGateway = new TableGateway($relation->relationTableName, static::adapter());
        if ($relation->order) {
            $ordering = array_combine(array_column($data, $linkedTableField), array_column($data, static::ORDERING_FIELD));
        }
        $newPositions = array_diff(array_column($data, $linkedTableField), array_keys($this->{$relation->paramName}));
        foreach ($newPositions as $position) {
            $columns = [$currentTableFieldInRelation => $this->{static::primaryKey()}, $linkedTableFieldInRelation => $position];
            if($relation->order && isset($ordering[$position])) {
                $columns[static::ORDERING_FIELD] = $ordering[$position];
            }
            $tableGateway->insert($columns);
        }
        $idsToDelete = array_diff(array_keys($this->{$relation->paramName}), array_column($data, $linkedTableField));
        if (count($idsToDelete) > 0) {
            $tableGateway->delete('`'.$currentTableFieldInRelation.'` = \''.$this->{static::primaryKey()}.'\' and `'.$linkedTableFieldInRelation.'` in(\''.implode('\',\'', $idsToDelete).'\')');
        }
        if ($relation->order) {
            $ordering = array_diff_key($ordering, array_flip($newPositions), array_flip($idsToDelete));
            foreach($ordering as $itemId => $ordering) {
                $tableGateway->update([static::ORDERING_FIELD => $ordering], [$currentTableFieldInRelation => $this->{static::primaryKey()}, $linkedTableFieldInRelation => $itemId]);
            }
        }
        unset($this->_related[$relation->paramName]);
    }

    /**
     * Extracts array of models
     * @param ActiveRecord[] $listOfModels
     * @param array|null $listOfFields
     * @param bool $keysAsIndexes
     * @return array
     */
    public static function extractList(array $listOfModels, $listOfFields = null, $keysAsIndexes = true)
    {
        $result = [];
        foreach ($listOfModels as $model) {
            if ($keysAsIndexes) {
                $result[$model->{$model::primaryKey()}] = $model->extract($listOfFields);
            } else {
                $result[] = $model->extract($listOfFields, $keysAsIndexes);
            }
        }
        return $result;
    }

    public static function fieldsByType($type)
    {
        $fields = array_keys(array_combine(array_keys(static::types()), array_column(static::types(), 'type')), $type);
        return $fields;
    }

    public static function mapDataFromSQL($data)
    {
        foreach (static::fieldsByType('set') as $field) {
            if (isset($data[$field])) {
                $data[$field] = array_filter(explode(',', $data[$field]));
            }
        }
        foreach (static::fieldsByType('boolean') as $field) {
            if (isset($data[$field])) {
                $data[$field] = (boolean) $data[$field];
            }
        }
        foreach (static::fieldsByType('int') as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        return $data;
    }

    public static function mapDataToSQL($data)
    {
        foreach (static::fieldsByType('set') as $field) {
            if (is_array($data[$field])) {
                $data[$field] = implode(',', $data[$field]);
            }
        }
        foreach (static::fieldsByType('boolean') as $field) {
            $data[$field] = (integer) $data[$field];
        }
        return $data;
    }

    public function getOrdering()
    {
        return $this->__ordering;
    }

    public function setOrdering($value)
    {
        $this->__ordering = $value;
    }
}
