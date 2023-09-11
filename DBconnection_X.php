<?php

namespace post_updater;

use PDO;
use PDOException;

class DBconnection_X
{
    protected $link;
    private $dns, $host, $dbname, $username, $password;

    public function __construct($host, $dbname, $username, $password)
    {
        $this->host = $host;
        $this->dbname = $dbname;
        $this->dns = "mysql:host=" . $this->host . ";dbname=" . $this->dbname. ";charset=utf8;";
        $this->username = $username;
        $this->password = $password;
        try {
            $this->link = new PDO($this->dns, $this->username, $this->password);
        } catch (PDOException $e) {
            exit("接続失敗: " . $e->getMessage() . "\n");
        }
        $this->link->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
        return $this->link;
    }

    /**
     * SELECT
     * @param string $tbl
     */

    public function getDatabase($tbl)
    {
        $tbl = trim($tbl);
        $sql = 'SELECT * FROM ' . $tbl;
        $sth = $this->link->prepare($sql);
        $sth->execute();
        $i = 0;
        while ($result = $sth->fetch(PDO::FETCH_ASSOC)) {
            $set[$i] = $result;
            $i++;
        }
        return $set;
    }

    /**
     * SELECT ONE
     * @param string $tbl
     */

    public function getDatabaseOne($tbl,$id)
    {
        $tbl = trim($tbl);
        $sql = "SELECT * FROM ${tbl} WHERE `ID` = ${id}";
        $sth = $this->link->prepare($sql);
        $sth->execute();
        $i = 0;
        while ($result = $sth->fetch(PDO::FETCH_ASSOC)) {
            $set[$i] = $result;
            $i++;
        }
        return $set;
    }

    /**
     * @param string $tbl
     * @param string $col
     */
    public function distinctDB($tbl, $col)
    {
        $tbl = trim($tbl);
        $col = trim($col);
        $sql = 'SELECT DISTINCT ' . $col . ' FROM ' . $tbl;
        $sth = $this->link->prepare($sql);
        $sth->execute();
        $i = 0;
        while ($result = $sth->fetch(PDO::FETCH_ASSOC)) {
            $set[$i] = $result;
            $i++;
        }
        return $set;
    }

    /**
     * INSERT
     * @param string $tbl
     * @param array $values
     * @return int lastInsertId
     */
    public function setDatabase($tbl, $values, $columns)
    {
        $tbl = trim($tbl);
        $columns = implode(",",$columns);
        $values = implode(",",$values);
            $query = "INSERT INTO `{$tbl}` ({$columns}) VALUES ({$values});";
        $stmt = $this->link->prepare($query);
        $stmt->execute();
        return $this->link->lastInsertId();
    }

    public function searchDatabase($tbl, $column, $val)
    {
        $tbl = trim($tbl);
        $sql = "SELECT * FROM " . $tbl . " WHERE `" . $column . "` LIKE '" . $val . "'";
        $sth = $this->link->prepare($sql);
        $sth->execute();
        $i = 0;
        while ($result = $sth->fetch(PDO::FETCH_ASSOC)) {
            $set[$i] = $result;
            $i++;
        }
        return $set;
    }

    /**
     * INSERT
     * @param string $tbl
     * @param array $columns
     * @param array $vals
     * @return array lastInsertId
     */
    public function Multi_searchDatabase($tbl, $columns, $vals){
        $tbl = trim($tbl);
        $search = array();
        for($i = 0;$i < count($columns);$i++){
            $search[] = "`" . $columns[$i] . "` LIKE '" . $vals[$i] . "'";
        }
        $search = implode(" AND ",$search);
        $sql = "SELECT * FROM " . $tbl . " WHERE " . $search;//SELECT * FROM `wp_postmeta` WHERE `post_id` = 20724 AND `meta_key` LIKE '_variation_description'
        $sth = $this->link->prepare($sql);
        $sth->execute();
        $i = 0;
        while ($result = $sth->fetch(PDO::FETCH_ASSOC)) {
            $set[$i] = $result;
            $i++;
        }
        return $set;
    }

    public function joinDatabase($tbl1, $tbl2, $column1, $column2)
    {
        $query = "select * from ${tbl1} inner join ${tbl2} on ${tbl1}.${column1} = ${tbl2}.${column2};";
        $stmt = $this->link->prepare($query);
        $stmt->execute();
        $i = 0;
        while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $set[$i] = $result;
            $i++;
        }
        return $set;
    }

    /**
     * UPDATE
     * @param string $tbl
     * @param array $values
     */
    public function updateDatabase($tbl,$values,$column,$id,$where)
    {
        $tbl = trim($tbl);
        $dbname = $this->dbname;
        if($values == NULL) $values = "''";
        if(!is_array($where) AND !is_array($id)){
            $query = "UPDATE `{$dbname}`.`{$tbl}` SET {$column} = {$values} WHERE `{$tbl}`.`{$where}` = '{$id}';";
        }else{
            $wq = "";
            for($i=0;$i<count($where);$i++){
                if($i == 0){
                    $wq = "`{$tbl}` . `{$where[$i]}` = '{$id[$i]}'";
                }else{
                    $wq .= " AND `{$tbl}` . `{$where[$i]}` = '{$id[$i]}'";
                }
            }
            $wq .= ";";
            $query = "UPDATE `{$dbname}`.`{$tbl}` SET {$column} = {$values} WHERE ".$wq;
        }
        if($stmt = $this->link->prepare($query)){
            $set = "成功しました:".$query;
        }else{
            $set = "失敗しました";
        }
        $stmt->execute();
        return $set;
    }
    public function insertDatabase($tbl,$id,$meta_key,$meta_value)
    {
        $tbl = trim($tbl);
        $dbname = $this->dbname;
        $query = "INSERT INTO `{$dbname}`.`{$tbl}` (`meta_id`, `post_id`, `meta_key`, `meta_value`) VALUES (NULL, $id, '$meta_key', '$meta_value')";
        if ($stmt = $this->link->prepare($query)) {
            $set = "成功しました:" . $query;
        } else {
            $set = "失敗しました";
        }
        $stmt->execute();
        return $set;

    }

    public function FukusuUpdateDatabase($tbl, $change, $id, $where)
    {
        $tbl = trim($tbl);
        $dbname = $this->dbname;
        $data = "";
        for($i=0;$i<count($change);$i++){
            if($i != count($change)-1){
                if($change[$i][1] != ""){
                    $data .= "`".$change[$i][0]."` = '".$change[$i][1]."',";
                }else{
                    $data .= "`" . $change[$i][0] . "` = NULL,";
                }
            }else{
                if ($change[$i][1] != "") {
                    $data .= "`" . $change[$i][0] . "` = '" . $change[$i][1]."'";
                } else {
                    $data .= "`" . $change[$i][0] . "` = NULL";
                }
            }
        }

        $query = "UPDATE `{$dbname}`.`{$tbl}` SET {$data} WHERE `{$tbl}`.`{$where}` = '{$id}';";
        if ($stmt = $this->link->prepare($query)) {
            $set = $query."成功しました";
        } else {
            $set = "失敗しました";
        }
        $stmt->execute();
        
        return $set;
    }

    /**
     * DELETE
     * @param string $tbl
     * @param int $id
     * @param string $where
     */
    public function deleteRow($tbl,int $id,$where){
        $tbl = trim($tbl);
        $dbname = $this->dbname;
        $query = "DELETE FROM `{$dbname}`.`{$tbl}` WHERE `{$tbl}`.`{$where}` = '{$id}';";
        if ($stmt = $this->link->prepare($query)) {
            $set = "削除に成功しました";
        } else {
            $set = "削除に失敗しました";
        }
        $stmt->execute();
        return $set;
    }
}

class CSV_controller
{
    private $filename;

    public function __construct($filename)
    {
        $this->filename = $filename;
    }

    public function readCSV(){

        if(!$lock = $this->lock($this->filename))die("ロックに失敗しました");

        while($line = fgetcsv($lock)){
            mb_convert_variables('utf-8', array('sjis'), $line);
            $csv_data[] = $line;
        }
        fclose($lock);
        return $csv_data;
    }
    
    function lock($lock)
    {
        if (!$fp= fopen($lock, "r")) return false;

        for ($i=0; $i<50; $i++){	// 50回トライ
            if (flock($fp, LOCK_EX | LOCK_NB)) return $fp;	// 書き込み宣言＋非ブロックモード
            else usleep(100000); // 0.1秒遅延
        }
        fclose($fp);
        return false;
    }

    public function writeCSV(){

    }
}

class arrayHtmlspecial
{

    function escape($array)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->escape($value);
            } else {
                $array[$key] = htmlspecialchars($value);
                $array[$key] = str_replace(array("\r\n", "\n", "\r"),"",$array[$key]);
            }
        }
        return $array;
    }
}
