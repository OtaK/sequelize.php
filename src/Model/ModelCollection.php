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

    namespace Sequelize\Model;

    // TODO use statements

    /**
     * Class ModelCollection
     * Lazy-instanciates models on iteration that goes easy on RAM.
     * @package Sequelize\Model
     */
    class ModelCollection implements \Iterator/*, \JsonSerializable*/, \Serializable, \Countable
    {
        /** @var Result - DB result to iterate on */
        private $_dbResult;

        /** @var string - Model Class */
        private $_modelClass;

        /** @var Model - Current model instance */
        private $_curModel;

        /** @var \Closure - callback to transform data */
        private $_beforeCallback;

        /** @var \Closure - callback to transform model after creation */
        private $_afterCallback;

        /** @var bool - Raw iteration of Result */
        public $raw;

        /**
         * CTOR
         * @param          $modelClass
         * @param Result $result
         * @param bool     $raw
         */
        public function __construct($modelClass, Result $result, $raw = false)
        {
            $this->_modelClass     = $modelClass;
            $this->_curModel       = null;
            $this->_dbResult       = $result;
            $this->_beforeCallback = null;
            $this->_afterCallback  = null;
            $this->raw             = $raw;
        }

        /**
         * @param callable $callback
         */
        public function before(\Closure $callback)
        {
            $this->_beforeCallback = $callback;
        }

        /**
         * @param callable $callback
         */
        public function after(\Closure $callback)
        {
            $this->_afterCallback = $callback;
        }

        /**
         * @return array
         */
        private function _toArray()
        {
            return $this->_dbResult->fetch_all();
        }

        /**
         * (PHP 5 &gt;= 5.0.0)<br/>
         * Return the current element
         * @link http://php.net/manual/en/iterator.current.php
         * @return mixed Can return any type.
         */
        public function current()
        {
            return $this->_curModel;
        }

        /**
         * (PHP 5 &gt;= 5.0.0)<br/>
         * Return the key of the current element
         * @link http://php.net/manual/en/iterator.key.php
         * @return mixed scalar on success, or null on failure.
         */
        public function key()
        {
            return $this->_dbResult->key();
        }

        /**
         * (PHP 5 &gt;= 5.0.0)<br/>
         * Checks if current position is valid
         * @link http://php.net/manual/en/iterator.valid.php
         * @return boolean The return value will be casted to boolean and then evaluated.
         * Returns true on success or false on failure.
         */
        public function valid()
        {
            return $this->_curModel !== null;
        }

        /**
         * (PHP 5 &gt;= 5.0.0)<br/>
         * Rewind the Iterator to the first element
         * @link http://php.net/manual/en/iterator.rewind.php
         * @return void Any returned value is ignored.
         */
        public function rewind()
        {
            $this->_dbResult->first();
            $this->next();
        }

        /**
         * (PHP 5 &gt;= 5.0.0)<br/>
         * Move forward to next element
         * @link http://php.net/manual/en/iterator.next.php
         * @return void Any returned value is ignored.
         */
        public function next()
        {
            $this->_dbResult->next();
            if (!$this->_dbResult->valid())
            {
                $this->_curModel = null;

                return;
            }

            $data = $this->_dbResult->current();
            if (null !== $this->_beforeCallback)
            {
                /** @noinspection PhpUndefinedMethodInspection */
                $data = $this->_beforeCallback($data);
            }

            if (!$this->raw)
            {
                $this->_curModel = new $this->_modelClass();
                $this->_curModel->assign($data);
                if (null !== $this->_afterCallback)
                {
                    /** @noinspection PhpUndefinedMethodInspection */
                    $this->_afterCallback($this->_curModel);
                }
            }
            else
                $this->_curModel = $data;
        }

        /**
         * @return array
         */
        public function pack()
        {
            $ids = array();
            foreach ($this as $model)
                $ids[] = $model->id();

            return array(
                'model' => $this->_modelClass,
                'ids' => $ids
            );
        }

        /**
         * @param $data
         * @return self
         */
        public static function unpack($data)
        {
            $model = $data['model'];
            return new static($model, $model::all(array(
                'where' => array(
                    $model::idField() => array(
                        'in' => $data['ids']
                    )
                )
            )));
        }

        /**
         * Returns collection as array
         * @return array
         */
        public function toArray()
        {
            $data = array();
            $class = $this->_modelClass;
            if (!$this->raw)
                foreach ($this->_dbResult as $d)
                    $data[] = $class::build($d);
            else
            {
                $fields = $class::fields();
                foreach ($this->_dbResult as $d)
                    $data[] = $class::datacast($d, $fields);
            }
            return $data;
        }

        /**
         * @todo
         * (PHP 5 &gt;= 5.4.0)<br/>
         * Specify data which should be serialized to JSON
         * @link http://php.net/manual/en/jsonserializable.jsonserialize.php
         * @return mixed data which can be serialized by <b>json_encode</b>,
         * which is a value of any type other than a resource.
         */
        /*public function jsonSerialize()
        {
            return $this->_toArray();
        }*/

        /**
         * Json serialize
         * @return string
         */
        public function json()
        {
            return json_encode($this->pack());
        }

        /**
         * JSON-unserialize
         * @param $string
         * @return DBModelCollection
         */
        public static function unjson($string)
        {
            return static::unpack(json_decode($string, true));
        }

        /**
         * (PHP 5 &gt;= 5.1.0)<br/>
         * String representation of object
         * @link http://php.net/manual/en/serializable.serialize.php
         * @return string the string representation of the object or null
         */
        public function serialize()
        {
            return serialize($this->pack());
        }

        /**
         * (PHP 5 &gt;= 5.1.0)<br/>
         * Constructs the object
         * @link http://php.net/manual/en/serializable.unserialize.php
         * @param string $serialized <p>
         *                           The string representation of the object.
         *                           </p>
         * @return void
         */
        public function unserialize($serialized)
        {
            $obj = static::unpack(unserialize($serialized));
            $this->_modelClass = $obj->_modelClass;
            $this->_curModel = null;
            $this->_dbResult = $obj->_dbResult;
            $this->raw = $obj->raw;
        }

        /**
         * (PHP 5 &gt;= 5.1.0)<br/>
         * Count elements of an object
         * @link http://php.net/manual/en/countable.count.php
         * @return int The custom count as an integer.
         * </p>
         * <p>
         * The return value is cast to an integer.
         */
        public function count()
        {
            return $this->_dbResult ? $this->_dbResult->num_rows : -1;
        }
    }
