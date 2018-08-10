<?php

namespace CodeIT\ActiveRecord\Model;

class Relation
{
    public $className;
    public $link;
    public $hasMany;
    public $relationTableName;
    public $linkByTable;
    public $paramName;
    public $order;

    public function __construct($className, $link, $hasMany, $relationTableName, $linkByTable, $paramName, $order)
    {
        $this->className = $className;
        $this->link = $link;
        $this->hasMany = $hasMany;
        $this->relationTableName = $relationTableName;
        $this->linkByTable = $linkByTable;
        $this->paramName = $paramName;
        $this->order = $order;
    }
}