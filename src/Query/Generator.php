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
     * @package    Sequelize
     * @subpackage QueryGenerator
     * @author     Mathieu AMIOT <m.amiot@otak-arts.com>
     * @copyright  Copyright (c) 2015, Mathieu AMIOT
     * @version    1.0
     * @changelog
     *      1.0: Initial stable version
     */

    namespace Sequelize\Query;

    // TODO use statements
    use Sequelize\Connectors;

    /**
     * Class Generator
     * @package Sequelize\Query
     */
    abstract class Generator
    {
        protected $_db;
        protected $_table;

        public function __construct($table, Connectors\Connector $db = null)
        {
            $this->_table = $table;
            $this->_db    = $db ?: Connector::get_instance();
        }

        private static function _queryGenerator($type, $table)
        {
            return new static\{$type}($table);
        }

        public static __callStatic($name, $args)
        {
            return self::_queryGenerator($name, $args[0]);
        }

        /**
         * @throws \Exception
         * @return DBResult
         */
        public function run()
        {
            $q = $this->getQuery();
            $res = $this->_db->query($q);
            if ($res === false)
                throw new \Exception($this->_db->error. ' ['.$q.']');

            return $res;
        }

        /**
         * escape function
         * @param      $value
         * @param bool $quoted
         * @return string
         */
        public function escape($value, $quoted = false)
        {
            if ($value === null)
                return null;

            $value = $this->_db->escape_string($value);
            if ($quoted)
                $value = "'$value'";

            return $value;
        }

        /**
         * Alias to escape
         * @see escape
         * @param      $value
         * @param bool $quoted
         * @return string
         */
        public function e($value, $quoted = false)
        {
            return $this->escape($value, $quoted);
        }

        /**
         * @return mixed
         */
        public function getInsertId()
        {
            return $this->_db->insert_id;
        }

        /**
         * @return int
         */
        public function affectedRows()
        {
            return $this->_db->affected_rows;
        }

        /**
         * @return string
         */
        abstract public function getQuery();
    }
