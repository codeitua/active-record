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
            $cashedPrimaryKey = $cache->get('primaryKey.'.$table);
            if (!empty($cashedStructure) || !empty($cashedPrimaryKey)) {
                $realStructure = [];
                $realPrimary = [];
                $realDefault = [];
                $structureRequest = $adapter->query('SHOW COLUMNS FROM `'.$table.'`')->execute();
                while ($row = $structureRequest->next()) {
                    $realStructure[] = $row['Field'];
                    $realDefault[] = $row['Default'];
                    if ($row['Key'] === 'PRI') {
                        $realPrimary = $row['Field'];
                    }
                }
                if (count(array_diff($realDefault, $cashedDefault)) > 0 || count(array_diff($cashedDefault, $realDefault)) > 0) {
                    $cache->set('default.'.$table, $realDefault);
                }
                if (count(array_diff($realStructure, $cashedStructure)) > 0 || count(array_diff($cashedStructure, $realStructure)) > 0 || $realPrimary !== $cashedPrimaryKey) {
                    $cache->set('structure.'.$table, $realStructure);
                    $cache->set('primaryKey.'.$table, $realPrimary);
                    $cache->deleteByMask('record.'.$table.'.');
                }
            }
        }
    }
}