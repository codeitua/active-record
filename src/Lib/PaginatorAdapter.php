<?php

namespace CodeIT\ActiveRecord\Lib;

use Zend\Db\Sql\Select;
/**
 * @property \CodeIT\ActiveRecord\Model\ActiveSelect $select
  */
class PaginatorAdapter extends \Zend\Paginator\Adapter\DbSelect
{

    public function __construct(\Zend\Db\Sql\Select $select, $adapterOrSqlObject, \Zend\Db\ResultSet\ResultSetInterface $resultSetPrototype = null, \Zend\Db\Sql\Select $countSelect = null)
    {

        parent::__construct($select, $adapterOrSqlObject, $resultSetPrototype, $countSelect);
    }

    public function getItems($offset, $itemCountPerPage)
    {
        $select = clone $this->select;
        $select->offset($offset);
        $select->limit($itemCountPerPage);
        $ds = clone $select;
        $sql = new \Zend\Db\Sql\Sql($ds::adapter());
        return $select->getList();
    }

    public function count()
    {
        if (is_null($this->rowCount)) {
            $select = clone $this->select;
            $select->reset(Select::LIMIT);
            $select->reset(Select::OFFSET);
            $select->reset(Select::ORDER);
            $this->rowCount = $select->count();
        }
        return $this->rowCount;
    }

}