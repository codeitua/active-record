<?php

namespace CodeIT\ActiveRecord\Validator;

use CodeIT\ActiveRecord\Model\ActiveRecord;
use Laminas\Validator\AbstractValidator;
use Laminas\Db\Sql\Where;

class NotExistValidator extends AbstractValidator
{
    private $model;
    private $field;
    private $key;

    const EXIST = 'aexist';

    protected $messageTemplates = [
        self::EXIST => 'aexist',
    ];

    /**
     * @param string $model ActiveRecord Class Name
     * @param string|[] $field
     * @param int|bool $key
     * @param string $message
     */
    public function __construct($model, $field, $key = false, $message = 'Item with the same value already exists')
    {
        parent::__construct();
        $this->messageTemplates = array(
            self::EXIST => $message,
        );
        $this->setMessage($message);
        $this->model = $model;
        $this->key = $key;
        $this->field = $field;
    }

    public function isValid($value)
    {
        /* @var $className ActiveRecord */
        $className = $this->model;
        $where = new Where();
        $where->equalTo($this->field, $value);

        if (!empty($this->key)) {
            $where->notEqualTo($className::primaryKey(), $this->key);
        }

        if ($className::query()->where($where)->getList()) {
            $this->error(self::EXIST);
            return false;
        } else {
            return true;
        }
    }
}
