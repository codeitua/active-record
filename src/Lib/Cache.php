<?php

namespace CodeIT\ActiveRecord\Lib;

class Cache
{

    public function check($table = null)
    {
        $adapter = \CodeIT\Utils\Registry::get('sm')->get('dbAdapter');
        $cache = \CodeIT\Utils\Registry::get('sm')->get('cache');
        if (!empty($table)) {
            $tables = [$table];
        } else {
            $tables = [];
            $tablesRequest = $adapter->query('SHOW TABLES')->execute();
            while ($row = $tablesRequest->next()) {
                $tables[] = array_values($row)[0];
            }
        }
        foreach ($tables as $table) {
            $cashedStructure = $cache->get('structure.'.$table);
            $cashedDefault = $cache->get('default.'.$table);
            $cashedTypes = $cache->get('types.'.$table);
            $cashedPrimaryKey = $cache->get('primaryKey.'.$table);
            if (!empty($cashedStructure) || !empty($cashedPrimaryKey)) {
                $realStructure = [];
                $realTypes = [];
                $realDefault = [];
                $structureRequest = $adapter->query('SHOW COLUMNS FROM `'.$table.'`')->execute();
                while ($row = $structureRequest->next()) {
                    $realStructure[] = $row['Field'];
                    $realDefault[] = $row['Default'];
                    $realTypes[$row['Field']] = \CodeIT\ActiveRecord\Model\ActiveRecord::prepareType($row['Type'], $row['Null']);
                    if ($row['Key'] === 'PRI') {
                        $realPrimary = $row['Field'];
                    }
                }
                if (count(array_diff($realDefault, $cashedDefault)) > 0 || count(array_diff($cashedDefault, $realDefault)) > 0) {
                    $cache->set('default.'.$table, $realDefault);
                }
                if (
                    !is_array($cashedStructure) ||
                    !is_array($cashedTypes) ||
                    !is_array($cashedTypes) ||
                    count(array_diff($realStructure, $cashedStructure)) > 0 ||
                    count(array_diff($cashedStructure, $realStructure)) > 0 ||
                    count(static::arrayRecursiveDiff($realTypes, $cashedTypes)) > 0 ||
                    count(static::arrayRecursiveDiff($cashedTypes, $realTypes)) ||
                    $realPrimary !== $cashedPrimaryKey
                ) {
                    $cache->set('structure.'.$table, $realStructure);
                    $cache->set('types.'.$table, $realTypes);
                    $cache->set('primaryKey.'.$table, $realPrimary);
                    $cache->deleteByMask('record.'.$table.'.');
                }
            }
        }
    }

    public static function arrayRecursiveDiff($aArray1, $aArray2)
    {
        $aReturn = array();

        foreach ($aArray1 as $mKey => $mValue) {
            if (array_key_exists($mKey, $aArray2)) {
                if (is_array($mValue)) {
                    $aRecursiveDiff = static::arrayRecursiveDiff($mValue, $aArray2[$mKey]);
                    if (count($aRecursiveDiff)) {
                        $aReturn[$mKey] = $aRecursiveDiff;
                    }
                } else {
                    if ($mValue != $aArray2[$mKey]) {
                        $aReturn[$mKey] = $mValue;
                    }
                }
            } else {
                $aReturn[$mKey] = $mValue;
            }
        }
        return $aReturn;
    }
}