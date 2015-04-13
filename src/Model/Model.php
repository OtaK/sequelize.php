<?php
    /*
     * Copyright 2015 Mathieu "OtaK_" Amiot <m.amiot@otak-arts.com> http://mathieu-amiot.fr/
     *
     * Licensed under the Apache License, Version 2.0 (the "License");
     * you may not use this file except in compliance with the License.
     * You may obtain a copy of the License at
     *
     *      http://www.apache.org/licenses/LICENSE-2.0
     *
     * Unless required by applicable law or agreed to in writing, software
     * distributed under the License is distributed on an "AS IS" BASIS,
     * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
     * See the License for the specific language governing permissions and
     * limitations under the License.
     *
     */

    /**
     * @package Sequelize
     * @subpackage Model
     * @author     Mathieu AMIOT <m.amiot@otak-arts.com>
     * @copyright  Copyright (c) 2015, Mathieu AMIOT
     * @version    1.0
     * @changelog
     *      1.0: First stable version
     */

    namespace Sequelize\Model;

    // TODO use statements

    /**
     * Class FieldNotDefinedException
     * @package Sequelize\Model
     */
    class FieldNotDefinedException extends \Exception
    {
        public function __construct($field)
        {
            parent::__construct("The field [$field] has not been defined in this model.");
        }
    }

    /**
     * Class Model
     * @package MuPHP\DB
     */
    abstract class Model
    {
        const TABLE_NAME = null;
        static protected $__idField = 'id';
        static protected $__fieldsDefinition = array(
            'id' => array(
                'type'          => 'UNSIGNED INT',
                'allowNull'     => false,
                'primaryKey'    => true,
                'autoIncrement' => true
            )
        );
        static protected $__foreignKeys = array();
        static protected $__enableTimestamps = true;
        static private $__fieldDefaults = array(
            'type'          => 'VARCHAR(255)',
            'allowNull'     => true,
            'primaryKey'    => false,
            'autoIncrement' => false,
            'index'         => false,
            'unique'        => false,
            'defaultValue'  => null,
            'comment'       => null,
            'values'        => null
        );
        static private $__timestampsDefinition = array(
            'created_at' => array(
                'type'         => 'TIMESTAMP',
                'allowNull'    => true,
                'defaultValue' => 'NULL'
            ),
            'updated_at' => array(
                'type'         => 'TIMESTAMP',
                'allowNull'    => true,
                'defaultValue' => 'NULL'
            )
        );
        private $_dirty;
        private $_fields;
        private $_values;

        /**
         * Ctor
         */
        public function __construct()
        {
            $this->_fields = self::fields();

            $this->_values = array();
            foreach ($this->_fields as $fieldName => $spec)
            {
                if ('CURRENT_TIMESTAMP' === $spec['defaultValue'])
                    $this->_values[$fieldName] = time();
                else
                    $this->_values[$fieldName] = $spec['defaultValue'];
            }

            if (static::$__enableTimestamps)
            {
                $this->_fields    = array_merge($this->_fields, static::$__timestampsDefinition);
                $this->created_at = time();
                $this->updated_at = null;
            }

            $this->_values[static::$__idField] = null;
            $this->_dirty                      = true;
        }

        /**
         * Gets table fields definition
         * @return array
         */
        public static function fields()
        {
            self::_normalizeFields();

            $tmp = array_merge(self::$__fieldsDefinition, static::$__fieldsDefinition);
            if ('id' !== static::$__idField)
                unset($tmp['id']);

            return $tmp;
        }

        /**
         * Provides a succint description of fields suited for public use
         */
        public static function meta()
        {
            $fields = self::fields();

            foreach ($fields as &$f)
            {
                $f['type'] = strtoupper($f['type']);
                if ('ENUM' !== $f['type'])
                    unset($f['values']);

                unset($f['allowNull'], $f['autoIncrement'], $f['index'], $f['unique'], $f['defaultValue']);
            }

            unset($f);

            return $fields;
        }

        /**
         * Finds a DAO-enabled object with given criteria
         * @param array|int|string $criteria
         * @return Model
         */
        public static function find($criteria)
        {
            $query = new DBSelectQueryGenerator(static::tableName());

            if (static::$__enableTimestamps)
            {
                $query->select(array('UNIX_TIMESTAMP' => 'created_at'), '_created_at_ts');
                $query->select(array('UNIX_TIMESTAMP' => 'updated_at'), '_updated_at_ts');
            }

            if (is_array($criteria))
            {
                if (!isset($criteria['where']))
                {
                    $tmp = array('where' => $criteria);
                    $criteria = $tmp;
                    unset($tmp);
                }

                // TODO retrieve included models and merge it in result collection
                self::_parseCriteria($criteria, $query);
            }
            else
                $query->where(static::$__idField, '=', $criteria);

            $query->limit(1);

            $row = $query->run()->fetch_assoc();

            if ($row === null)
                return null;

            if (static::$__enableTimestamps)
            {
                $row['updated_at'] = (int)$row['_updated_at_ts'];
                $row['created_at'] = (int)$row['_created_at_ts'];
                unset($row['_created_at_ts'], $row['_updated_at_ts']);
            }

            return static::_unpackModel($row);
        }

        /**
         * Gets linked table name for current class
         * @return string
         */
        public static function tableName()
        {
            if (static::TABLE_NAME !== null)
                return static::TABLE_NAME;

            $class = get_called_class();

            return self::Uncamelize(end($class));
        }

        /**
         * Returns a collection of DAO-enabled objects
         * @param array $criteria
         * @param bool  $raw
         * @return array|ModelCollection
         */
        public static function all(array $criteria = null, $raw = false)
        {
            $query = new DBSelectQueryGenerator(static::tableName());

            if (static::$__enableTimestamps)
            {
                $query->select(array('UNIX_TIMESTAMP' => 'created_at'), '_created_at_ts');
                $query->select(array('UNIX_TIMESTAMP' => 'updated_at'), '_updated_at_ts');
            }

            if ($criteria !== null)
                self::_parseCriteria($criteria, $query);

            $collection = new ModelCollection(get_called_class(), $query->run(), $raw);
            if (static::$__enableTimestamps)
            {
                $collection->before(function ($row)
                {
                    $row['updated_at'] = (int)$row['_updated_at_ts'];
                    $row['created_at'] = (int)$row['_created_at_ts'];
                    unset($row['_created_at_ts'], $row['_updated_at_ts']);
                });
            }

            return $collection;
        }

        /**
         * Deletes an id from the table
         * @param $criteria
         * @return bool
         */
        public static function destroy($criteria)
        {
            $query = new DBDeleteQueryGenerator(static::tableName());
            if (is_array($criteria))
            {
                foreach ($criteria as $field => $val)
                {
                    if (!is_array($val))
                        $query->where($field, '=', $val);
                    else
                        foreach ($val as $op => $value)
                            $query->where($field, $op, $value);
                }
            }
            else
                $query->where(static::$__idField, '=', $criteria);

            return $query->run();
        }

        /**
         * Parses query array param and translates it to selectquerygen
         * @param array                  $criteria
         * @param DBSelectQueryGenerator &$query
         * @return array - return included models
         */
        private static function _parseCriteria(array $criteria, DBSelectQueryGenerator &$query)
        {
            $ret = array();
            if (isset($criteria['attributes']))
            {
                foreach ($criteria['attributes'] as $k => $v)
                {
                    if (is_int($k))
                        $query->select($v);
                    else
                        $query->select($v, $k);
                }
            }

            if (isset($criteria['where']))
            {
                foreach ($criteria['where'] as $field => $val)
                {
                    if (!is_array($val))
                        $query->where($field, '=', $val);
                    else
                        foreach ($val as $op => $value)
                            $query->where($field, $op, $value);
                }
            }

            if (isset($criteria['group']))
                foreach ($criteria['group'] as $g)
                    $query->groupBy($g);

            if (isset($criteria['order']))
                foreach ($criteria['order'] as $field => $type)
                    $query->orderBy($field, strtoupper($type) === 'ASC');

            if (isset($criteria['limit']))
                $query->limit($criteria['limit'], isset($criteria['offset']) ? $criteria['offset'] : null);

            if (isset($criteria['include']))
            {
                foreach ($criteria['include'] as $includedModel)
                {
                    if (!isset(static::$__foreignKeys[$includedModel]))
                        continue;

                    $ret[$includedModel] = static::$__foreignKeys[$includedModel](get_called_class());
                }
            }

            return $ret;
        }

        /**
         * Instanciates an object + saves it instantly and then returns it
         * @param array $data
         * @param array $fields
         * @return Model
         */
        public static function create(array $data, array $fields = array())
        {
            /** @var Model $obj */
            $obj = static::_unpackModel($data, $fields);
            $obj->save();

            return $obj;
        }

        /**
         * Instanciates an unsaved object
         * @param array $data
         * @param array $fields
         * @return Model
         */
        public static function build(array $data, array $fields = array())
        {
            $obj         = static::_unpackModel($data, $fields);
            $obj->_dirty = true;

            return $obj;
        }

        /**
         * Uncamelizes a string. MyExample => my_example
         * Used for ModelName => table_name translation here
         * @param $str
         * @return string
         */
        public static function Uncamelize($str)
        {
            $str    = lcfirst($str);
            $lc     = strtolower($str);
            $result = '';
            for ($i = 0, $l = strlen($str); $i < $l; ++$i)
                $result .= ($str[$i] === $lc[$i] ? '' : '_') . $lc[$i];

            return $result;
        }

        /**
         * Camelizes a string. my_example => MyExample
         * Used for table_name => ModelName translation here
         * @param $str
         * @return string
         */
        public static function Camelize($str)
        {
            return str_replace(' ', '', ucwords(str_replace('_', ' ', $str)));
        }

        /**
         * @param string $modelClass
         * @param null   $field
         * @param null   $modelField
         */
        public static function BelongsTo($modelClass, $field = null, $modelField = null)
        {
            static::HasOne($modelClass, $field, $modelField);
            $modelClass::HasMany(get_called_class(), $modelField, $field);
        }

        /**
         * @param string $modelClass
         * @param null   $field
         * @param null   $modelField
         */
        public static function HasOne($modelClass, $field = null, $modelField = null)
        {
            $field      = $field ? : $modelClass::idField();
            $modelField = $modelField ? : $modelClass::idField();

            static::$__foreignKeys[$modelClass] = function ($model) use ($modelClass) {
                return $modelClass::find($model->id());
            };
        }

        /**
         * @param string $modelClass
         * @param null   $field
         * @param null   $modelField
         */
        public static function HasMany($modelClass, $field = null, $modelField = null)
        {
            $field      = $field ? : $modelClass::idField();
            $modelField = $modelField ? : $modelClass::idField();

            static::$__foreignKeys[$modelClass] = function ($model) use ($modelClass) {
                return $modelClass::all(array(
                    'where' => array($modelClass::idField() => $model->id())
                ));
            };
        }

        /**
         * Normalizes fields with default values
         */
        private static function _normalizeFields()
        {
            foreach (self::$__fieldsDefinition as &$definition)
                $definition = array_merge(self::$__fieldDefaults, $definition);

            unset($definition);
            if (static::$__fieldsDefinition !== self::$__fieldsDefinition)
            {
                foreach (static::$__fieldsDefinition as &$definition)
                    $definition = array_merge(self::$__fieldDefaults, $definition);

                unset($definition);
            }
        }

        /**
         * Creates a model initiated with given data
         * @param array $data hash of data to assign
         * @param array $fields
         * @return Model
         */
        private static function _unpackModel(array $data, array $fields = array())
        {
            /** @var Model $obj */
            $obj = new static();
            $obj->assign($data, $fields);
            $obj->_dirty = false;

            return $obj;
        }

        /**
         * @param array $data
         * @param array $fields
         * @return array
         */
        public static function datacast(array &$data, array $fields)
        {
            foreach ($fields as $f => $d)
            {
                if (!isset($data[$f])) continue;
                $data[$f] = self::_castField($d, $data[$f]);
            }

            return $data;
        }

        /**
         * Mass assignement method with optional field assignement filtering
         * @param array $data   hash of data to assign
         * @param array $fields optional list of fields to assign
         * @return self
         */
        public function assign(array $data, array $fields = array())
        {
            $fn = count($fields) > 0;
            foreach ($data as $field => $val)
            {
                if (($fn && in_array($field, $fields, true))
                || !isset($this->_fields[$field]))
                    continue;

                $this->{$field} = $val;
            }

            return $this;
        }


        /**
         * Casts a field from MySQL to PHP primitive types
         * @param $field
         * @param $value
         * @return bool|float|int|string
         */
        static private function _castField($field, $value)
        {
            $fType = strtoupper($field['type']);
            if ($fType === 'BOOLEAN' || $fType === 'TINYINT(1)')
                return (bool)$value;

            if (strpos($fType, 'VARCHAR') !== false)
                return (string)$value;

            if (strpos($fType, 'INT') !== false)
                return (int)$value;

            if (strpos($fType, 'DECIMAL') !== false
            || strpos($fType, 'FLOAT') !== false
            || strpos($fType, 'DOUBLE') !== false)
                return (double)$value;

            return $value;
        }


        /**
         * Saves current DAO model.
         * Inserts if new, or updates if already in DB.
         * Returns true on successful modification
         * @param array $data
         * @param array $fields
         * @return bool
         */
        public function save(array $data = array(), array $fields = array())
        {
            if (count($data) > 0 && count($fields) > 0)
                $this->assign($data, $fields);

            $insert    = $this->{static::$__idField} === null;
            $className = '\\MuPHP\\DB\\QueryGenerator\\' . ($insert ? "DBInsertQueryGenerator" : "DBUpdateQueryGenerator");
            /** @var DBInsertQueryGenerator|DBUpdateQueryGenerator $query */
            $query = new $className(static::tableName());
            foreach ($this->_values as $name => $var)
            {
                if ($name !== static::$__idField)
                    $query->set($name, $var);
            }

            if (!$insert)
            {
                $query->where(static::$__idField, '=', $this->{static::$__idField});
                if (static::$__enableTimestamps)
                {
                    $this->updated_at = time();
                    $query->set('updated_at', 'FROM_UNIXTIME(' . $this->updated_at . ')', false);
                }
            }
            else
            {
                if (static::$__enableTimestamps)
                {
                    $this->created_at = time();
                    $query->set('created_at', 'FROM_UNIXTIME(' . $this->created_at . ')', false);
                }

                $onDuplicate = false;
                foreach ($this->_fields as $fieldName => $def)
                {
                    if (!isset($def['onDuplicate'])) continue;
                    if (!$onDuplicate) // Make LAST_INSERT_ID() meaningful to retrieve last id
                    {
                        $query->onDuplicateKey(static::$__idField, 'LAST_INSERT_ID(`' . static::$__idField . '`)', false);
                        $onDuplicate = true;
                    }
                    $query->onDuplicateKey($fieldName, $def['onDuplicate'], false);
                }
            }

            $query->run();

            if ($insert)
                $this->{static::$__idField} = $query->getInsertId();

            $this->_dirty = false;

            return $query->affectedRows() > 0;
        }

        /**
         * @param array $fields
         * @return array
         */
        public function values(array $fields = array())
        {
            if (count($fields) === 0)
                return $this->_values;

            $tmp = array();
            foreach ($fields as $f)
                $tmp[$f] = $this->_values[$f];

            return $tmp;
        }

        /**
         * Returns model identifier
         * @return mixed
         */
        public function id()
        {
            return $this->{static::$__idField};
        }

        /**
         * @return string
         */
        public static function idField()
        {
            return static::$__idField;
        }

        /**
         * Magic isset accessor
         * @param $name
         * @return bool
         */
        public function __isset($name)
        {
            return isset($this->_fields[$name]);
        }

        /**
         * Magic getter
         * @param $name
         * @return mixed
         * @throws FieldNotDefinedException
         */
        public function __get($name)
        {
            if (isset($this->_fields[$name]))
                return $this->_values[$name];

            throw new FieldNotDefinedException($name);
        }

        /**
         * Magic setter for values
         * @param $name
         * @param $value
         */
        public function __set($name, $value)
        {
            if (isset($this->_fields[$name]))
            {
                $this->_values[$name] = static::_castField($this->_fields[$name], $value);
                $this->_dirty         = true;
            }
        }

        /**
         * Checks if the current DAO is dirty
         * @return bool
         */
        public function isDirty()
        {
            return $this->_dirty;
        }
    }
