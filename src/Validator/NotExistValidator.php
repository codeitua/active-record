<?php

namespace CodeIT\ActiveRecord\Validator;

use CodeIT\ActiveRecord\Model\ActiveRecord;
use Zend\Validator\AbstractValidator;
use Zend\Db\Sql\Expression;

class NotExistValidator extends AbstractValidator{

	private $model;
	private $field;
	private $key;

	const EXIST = 'aexist';

	protected $messageTemplates = array(
		self::EXIST => 'aexist',
	);

	/**
	 * @param string ActiveRecord Class Name
	 * @param string|[] $field
	 * @param int $key
	 * @param int $message
	 */
	public function __construct($model, $field, $key = false, $message = 'Item with the same value already exists'){
		parent::__construct();
		$this->messageTemplates = array(
			self::EXIST => $message,
		);
		$this->setMessage($message);
		$this->model = $model;
		$this->key = $key;
		$this->field = $field;
	}

	public function isValid($value){
		$where = new \Zend\Db\Sql\Where();
		$where->equalTo($this->field, $value);
		if(!empty($this->key)){
			$where->notEqualTo($this->model::primaryKey(), $this->key);
		}
		if($this->model::query()->where($where)->getList()){
			$this->error(self::EXIST);
			return false;
		}else{
			return true;
		}
	}

}
