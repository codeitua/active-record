<?php

namespace CodeIT\ActiveRecord\Validator;

use Zend\Validator\AbstractValidator;
use CodeIT\ActiveRecord\Model\ActiveRecord;

class ExistValidator extends AbstractValidator
{
    /**
     * @var ActiveRecord[]
     */
    private $models;
    private $fields;

    const NOTEXIST = 'aexist';

    protected $messageTemplates = array(
        self::NOTEXIST => 'aexist',
    );

    /**
     * @param ActiveRecord[] $models
     * @param string|[] $fields
     * @param string $message
     * @throws \Exception
     */
    public function __construct($models, $fields, $message = 'Item is not found')
    {
        parent::__construct();

        $this->messageTemplates = array(
            self::NOTEXIST => $message,
        );

        $this->setMessage($message);
        if (!is_array($models)) {
            $models = [$models];
        }

        if (!is_array($fields)) {
            $fields = [$fields];
        }

        foreach ($models as $model) {
            if (!is_subclass_of($model, '\CodeIT\ActiveRecord\Model\ActiveRecord')) {
                throw new \Exception('Bad model passed: \CodeIT\ActiveRecord\Model\ActiveRecord expected');
            }
        }

        $this->models = $models;
        $this->fields = $fields;
    }

    public function isValid($value)
    {
        foreach ($this->models as $model) {
            foreach ($this->fields as $field) {
                if (count($model::query()->where([$field => $value])->getList())) {
                    return true;
                }
            }
        }

        $this->error(self::NOTEXIST);
        return false;
    }
}