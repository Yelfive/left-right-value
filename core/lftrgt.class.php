<?php

include_once __DIR__ . '/query.class.php';

/**
 * Class to create,delete or move item(one whole row),according to the left-right-value method of ordering
 *
 * <method> int before(int $src, int $dst) Create an item before another
 * <method> int after(int $src, int $dst) Create an item after another
 * <method> int prepend(int $parent, array $data)  Create an item to a parent item as the first child
 * <method> int append(int $parent, array $data)   Create an item to a parent item as the last child
 * <method> null moveBefore(int $src, int $dst)   Move an item before another
 * <method> null moveAfter(int $drc, int $dst)      Move an item after another
 * <method> null movePrepend(int $src, int $parent)  Move an item to a parent item as the first child
 * <method> null moveAppend(int $src, int $parent)   Move an item to a parent item as the last child
 * <method> null after(int $src, int $dst) Description
 * <method> null delete(id)  Delete an item ,according to the given id
 * <method> array children
 * <method> array descendants
 * <method> array siblings
 * <method> boolean deleteDescendants
 *  For a find method, if you want to define an order, call orderBy() before find <br>
 *  e.g. <br>
 *  $lr->orderBy('ASC')->descendant($id);
 */
class LftRgt
{
    /**
     * @var int The lft value with which ,the whole table should start
     */
    public $veryLft = 1;

    /**
     * @var int The level value with which ,the whole table should start
     */
    public $veryLevel = 0;

    protected $order = '';

    /**
     * @var Resource of database connection
     */
    protected $db;

    /**
     * @var string Table name ,where this algorithm will be used on
     */
    protected $table;

    /**
     * @var array  Database config parameters
     */
    protected $dbConfig;

    protected $lftField;
    protected $rgtField;
    protected $levelField;
    protected $idField;

    /**
     * @param array $dbConfig Config array contains $host, $user, $password, $database[, $port, $socket]
     * @param string $table
     */
    public function __construct($dbConfig = array(), $table = '')
    {
        // Set db table name
        $this->table = $table;
        // Set db connect params
        $this->dbConfig = $dbConfig;
        // Create db link
        $this->db = new Query($dbConfig);
        // Set ignore list while moving items
        $this->defineNames();
        // Transaction
        $this->db->beginTransaction();
    }

    public function __destruct()
    {
        $this->db->commit();
    }

    /**
     * @return Query|resource
     */
    public function getDb()
    {
        return $this->db;
    }

    /**
     * Method to define column names to correspond to the database
     * @param string $id ID
     * @param string $lft Left
     * @param string $rgt Right
     * @param string $level Level
     */
    public function defineNames($id = 'id', $lft = 'lft', $rgt = 'rgt', $level = 'level')
    {
        $this->idField = $id;
        $this->lftField = $lft;
        $this->rgtField = $rgt;
        $this->levelField = $level;
    }

    protected function backQuotes($name)
    {
        return 0 === strpos($name, '`') ? $name : "`$name`";
    }

    /**
     *
     * Method to create before one item
     * @param int $id Later item id
     * @param array $new Columns other than id,lft,rgt,level to insert
     * @return boolean
     */
    public function before($id, $new = array())
    {
        $this->_checkExists($id);
        if (empty($new)) {
            return false;
        }

        $idField = $this->idField;

        $db = $this->db;
        // Find the item before current one
        $db->clearQuery();
        $item = $db->select("b.$this->idField,b.$this->lftField,b.$this->rgtField,b.$this->levelField")
            ->from("$this->table AS b")
            ->join("$this->table AS a ON a.$this->levelField=b.$this->levelField")
            ->join("$this->table AS p ON (a.$this->lftField>p.$this->lftField AND a.$this->lftField<p.$this->rgtField AND p.$this->levelField=a.$this->levelField-1)")
            ->where("a.$this->idField=$id")
            ->where("b.$this->rgtField<a.$this->lftField AND (b.$this->rgtField<p.$this->rgtField AND b.$this->lftField>p.$this->lftField)")
            ->order("`b`.`$this->lftField` DESC")
            ->find();
        // If former item is not found,the current item might be the first one,then prepend from its parent
        // In that case, the parent should be select first.
        if (empty($item)) {
            // Try to find the parent item
            $db->clearQuery();
            $item = $db->select("p.$this->idField")
                ->from("$this->table AS a")
                ->join("$this->table AS p ON (a.$this->lftField>p.$this->lftField AND a.$this->lftField<p.$this->rgtField AND p.$this->levelField=a.$this->levelField-1)")
                ->where("a.$this->idField=$id")
                ->find();
            // If not found,then the item is the root item
            if (empty($item->$idField)) {
                $db->clearQuery();
                $item = $db->select("b.$this->idField")
                    ->from($this->table . ' AS c')
                    ->join("$this->table AS b ON b.$this->rgtField<c.$this->lftField")
                    ->where("c.$this->idField=$id")
                    ->order("`b`.`$this->lftField` DESC")
                    ->find();
                // When there is not former item on root level
                if (empty($item->$idField)) {
                    // Step 1) Update
                    $db->clearQuery();
                    $db->update($this->table, "`$this->lftField`=`$this->lftField`+2,`$this->rgtField`=`$this->rgtField`+2")
                        ->execute();
                    // Step 2) Insert new data
                    $data = array(
                        $this->lftField => $this->veryLft,
                        $this->rgtField => $this->veryLft + 1,
                        $this->levelField => $this->veryLevel,
                    );
                    $insertArray = array_merge($data, $new);
                    return $this->setNewItem($insertArray);
                    // When there is an former item on root level
                } else {
                    return $this->after($item->$idField, $new);
                }
                // When current item is the first on this level and the parent item is found
            } else {
                return $this->prepend($item->$idField, $new);
            }
            // When former item is found
        } else {
            return $this->after($item->$idField, $new);
        }
    }

    /**
     * Method to create after  one item
     * @param int $id Former item id
     * @param array $new New columns except the id,lft,rgt,level
     * @return boolean
     * @throws Exception
     */
    public function after($id, $new = array())
    {
        $this->_checkExists($id);
        if (empty($new)) {
            return false;
        }

        $rgtField = $this->rgtField;
        $levelField = $this->levelField;
        $idField = $this->idField;

        $db = $this->db;
        $db->clearQuery();
        $item = $db->select("`$this->idField`,`$this->lftField`,`$this->rgtField`,`$this->levelField`")
            ->from($this->table)
            ->where("$this->idField=$id")
            ->find();
        if (empty($item->$idField)) {
            throw new Exception('Invalid former item given');
        }

        // Step 1) Update
        // lft rgt level
        $db->clearQuery();
        $db->update($this->table, "`$this->lftField`=`$this->lftField`+2")
            ->where("`$this->lftField`>{$item->$rgtField}")
            ->execute();

        $db->clearQuery();
        $db->update($this->table, "`$this->rgtField`=`$this->rgtField`+2")
            ->where("`$this->rgtField`>{$item->$rgtField}")
            ->execute();
        // Step 2) Insert new data
        $data = array(
            $this->lftField => $item->$rgtField + 1,
            $this->rgtField => $item->$rgtField + 2,
            $this->levelField => $item->$levelField,
        );
        $insertArray = array_merge($data, $new);
        return $this->setNewItem($insertArray);
    }

    /**
     * Method to prepend a new item,A.K.A create a new item after a parent item
     * @param int $id The id of which item that should be prepended
     * @param array $new Columns that need adding to db .However, lft,rgt,level,id are excluded
     * @return boolean
     * Step 1) Update the items that is after the selected id
     * Step 2) Insert new item that entered
     */
    public function prepend($id, $new = array())
    {
        $this->_checkExists($id);
        if (empty($new)) {
            return false;
        }

        $lftField = $this->lftField;
        $levelField = $this->levelField;
        $db = $this->db;
        $db->clearQuery();
        $item = $db->select("$this->idField,$this->lftField,$this->rgtField,$this->levelField")
            ->from($this->table)
            ->where([$this->idField => $id])
            ->find();
        // Step 1) Update
        $db->clearQuery();
        $db->update($this->table, "`$this->lftField`=`$this->lftField`+2")
            ->where("`$this->lftField`>{$item->$lftField}")
            ->execute();

        $db->clearQuery();
        $db->update($this->table, "`$this->rgtField`=`$this->rgtField`+2")
            ->where("`$this->rgtField`>{$item->$lftField}")
            ->execute();
        // Step 2) Insert new data
        $data = array(
            $this->lftField => $item->$lftField + 1,
            $this->rgtField => $item->$lftField + 2,
            $this->levelField => $item->$levelField + 1,
        );
        $insertArray = array_merge($data, $new);
        return $this->setNewItem($insertArray);
    }

    /**
     * Method to append an item
     * @param int $id Item id of witch the new one should append
     * @param array $new Columns that should be added ,excluding id,lft,rgt,level
     * @return boolean
     * @throws Exception
     */
    public function append($id, $new = array())
    {

        if (empty($new)) {
            return false;
        }

        $lftField = $this->lftField;
        $rgtField = $this->rgtField;
        $levelField = $this->levelField;

        if ($id === null) {
            return 0 === $this->db->from($this->table)->count() ? $this->create($new) : false;
        }
        $db = $this->db;
        $db->clearQuery();
        $p = $db->select("$this->idField,$this->lftField,$this->rgtField,$this->levelField")
            ->from($this->table)
            ->where([$this->idField => $id])
            ->find();
        if (empty($p)) {
            throw new Exception('Invalid parent item given');
        }
        $db->clearQuery();
        $child = $db->select("$this->idField,$this->lftField,$this->rgtField,$this->levelField")
            ->from($this->table)
            ->where("`$this->lftField`>{$p->$lftField}")
            ->where("`$this->rgtField`<{$p->$rgtField}")
            ->where("`$this->levelField`={$p->$levelField}+1")
            ->order("`$this->lftField` DESC")
            ->find();
        // If there is no child of the parent
        if (empty($child)) {
            // Step 1) Update
            $db->clearQuery();
            $db->update($this->table, "`$this->lftField`=`$this->lftField`+2")
                ->where("`$this->lftField`>{$p->$lftField}")
                ->execute();

            $db->clearQuery();
            $db->update($this->table, "`$this->rgtField`=`$this->rgtField`+2")
                ->where("`$this->rgtField`>{$p->$lftField}")
                ->execute();
            // Step 2) Insert new data
            $data = array(
                $this->lftField => $p->$lftField + 1,
                $this->rgtField => $p->$lftField + 2,
                $this->levelField => $p->$levelField + 1,
            );
            // If there is
        } else {
            // Step 1) Update
            $db->clearQuery();
            $db->update($this->table, "`$this->lftField`=`$this->lftField`+2")
                ->where("`$this->lftField`>{$child->$rgtField}")
                ->execute();

            $db->clearQuery();
            $db->update($this->table, "`$this->rgtField`=`$this->rgtField`+2")
                ->where("`$this->rgtField`>{$child->$rgtField}")
                ->execute();
            // Step 2) Insert new data
            $data = array(
                $this->lftField => $child->$rgtField + 1,
                $this->rgtField => $child->$rgtField + 2,
                $this->levelField => $child->$levelField,
            );
        }
        $insertArray = array_merge($data, $new);
        return $this->setNewItem($insertArray);
    }

    /**
     *
     * Method to move $src to before $dst
     * @param int $src Id of source item
     * @param int $dst Id of destination item
     * @return boolean If succeeded
     */
    public function moveBefore($src, $dst)
    {
        if ($src == $dst || $this->isRightBefore($src, $dst)) {
            return false;
        }

        $lftField = $this->lftField;
        $rgtField = $this->rgtField;
        $levelField = $this->levelField;
        $idField = $this->idField;

        $db = $this->db;
        /**
         * Case 1
         *      $dst is the first item
         * Case 1.1
         *      $dst has a parent item,move prepend
         * Case 1.2
         *      $dst has no parent item,which means $dst is at root,and is the first item
         * Case 2
         *      $dst is not the first item,which is the easiest case
         */
        // Try finding the item before $dst
        $db->clearQuery();
        $db->select("f.$this->idField")
            ->from("$this->table AS f")
            ->join("$this->table AS l ON f.$this->rgtField=l.$this->lftField-1 AND f.$this->levelField=l.$this->levelField")
            ->where("l.$this->idField = $dst");
        $formerItem = $db->find();

        if (empty($formerItem) || empty($formerItem->$idField)) {
            // Case 1
            $db->clearQuery();
            $db->select("p.$this->idField")
                ->from("$this->table AS d")
                ->join("$this->table AS p ON p.$this->lftField<d.$this->lftField AND p.$this->rgtField>d.$this->rgtField AND p.$this->levelField=d.$this->levelField-1")
                ->where("`d`.`$this->idField`=$dst");
            $prtItem = $db->find();
            if (empty($prtItem) || empty($prtItem->$idField)) {
                // Case 1.2
                $dstItem = new stdClass();
                $dstItem->$lftField = $this->veryLft - 2;
                $dstItem->$rgtField = $this->veryLft - 1;
                $dstItem->$levelField = $this->veryLevel;
                return $this->moveAfter($src, null, $dstItem);
            } else {
                // Case 1.1
                return $this->movePrepend($src, $prtItem->$idField);
            }
        } else {
            // Case 2: moveAfter former
            return $this->moveAfter($src, $formerItem->$idField);
        }
    }

    /**
     * Method to move $src to after $dst
     * @param int $src Id of source item
     * @param int $dst Id of destination item
     * @param object $dstItem See moveBefore,case 1.2,when $dst is empty
     * @return boolean
     */
    public function moveAfter($src, $dst, $dstItem = null)
    {
        if ($src == $dst || $this->isRightBefore($dst, $src)) {
            return false;
        }
        $lftField = $this->lftField;
        $rgtField = $this->rgtField;
        $levelField = $this->levelField;
        /**
         * Case 1:
         *      $src behind $dst
         * Case 2:
         *      $src before $dst
         */
        $srcItem = $this->getItemById($src);
        $dst && $dstItem = $this->getItemById($dst);

        $db = $this->db;
        // The diff between srcRgt and srcLft;
        $srcDiff = ($srcItem->$rgtField - $srcItem->$lftField + 1);
        // Get level diff
        $levelDiff = $srcItem->$levelField - $dstItem->$levelField;
        // Get the max and min value as condition for updating between
        if ($dstItem->$rgtField < $srcItem->$rgtField) { // Move foward
            $max = $srcItem->$lftField + 1;
            $min = $dstItem->$rgtField;
        } else { // Move back
            $max = $dstItem->$rgtField + 1;
            $min = $srcItem->$rgtField;
            // When the src is behind dst,srcDiff is a positive number
            // Otherwise,srcDiff is a negative one
            $srcDiff *= -1;
        }

        // Set src to nowhere
        // Set the items in src item to some figure that will never be take,negative ones for example
        // Thus ,to prevent duplicate left or right value
        $minusMax = $this->veryLft - 10;
        $offset = $srcItem->$rgtField - $minusMax;
        $db->clearQuery()
            ->update($this->table, "`$this->lftField`=`$this->lftField`-$offset")
            ->where("`$this->lftField`>={$srcItem->$lftField}")
            ->where("`$this->lftField`<{$srcItem->$rgtField}")
            ->execute();
        $db->clearQuery()
            ->update($this->table, "`$this->rgtField`=`$this->rgtField`-$offset")
            ->where("`$this->rgtField`>{$srcItem->$lftField}")
            ->where("`$this->rgtField`<={$srcItem->$rgtField}")
            ->execute();

        // Update items between
        $db->clearQuery()
            ->update($this->table, "`$this->lftField`=`$this->lftField`+$srcDiff")
            ->where("`$this->lftField`>$min")
            ->where("`$this->lftField`<$max")
            ->execute();
        $db->clearQuery()
            ->update($this->table, "`$this->rgtField`=`$this->rgtField`+$srcDiff")
            ->where("`$this->rgtField`>$min")
            ->where("`$this->rgtField`<$max")
            ->execute();
        // Update items in src item
        // Update the level value
        // level cannot be updated with lft and rgt value,because in that way, level would self-increased by two
        $db->clearQuery()
            ->update($this->table, "`$this->levelField`=`$this->levelField`-$levelDiff")
            ->where("`$this->rgtField`<=$minusMax")
            ->execute();
        // Refresh left and right values of source & destination items
        $srcItem = $this->getItemById($src);
        if ($dst) {
            $dstItem = $this->getItemById($dst);
        } else {
            $dstItem->$rgtField > $min
            && $dstItem->$rgtField < $max
            && $dstItem->$rgtField += $srcDiff;
            $dstItem->$lftField > $min && $dstItem->$lftField < $max && $dstItem->$lftField += $srcDiff;
        }

        $diff = ($dstItem->$rgtField + 1) - $srcItem->$lftField;
        $db->clearQuery()
            ->update($this->table, "`$this->lftField`=`$this->lftField`+$diff")
            ->where("`$this->lftField`<$minusMax")
            ->execute();
        $db->clearQuery()
            ->update($this->table, "`$this->rgtField`=`$this->rgtField`+$diff")
            ->where("`$this->rgtField`<=$minusMax")
            ->execute();
        return true;
    }

    /**
     * Method to move $src to a parent item ,$prt
     * And it is move as the first child of the parent
     * @param int $src Id of source item
     * @param int $prt Id of destination parent item
     * @return boolean indicates if succeeded
     */
    public function movePrepend($src, $prt)
    {
        if ($this->isParents($src, $prt)) {
            return false;
        }

        $lftField = $this->lftField;
        $rgtField = $this->rgtField;
        $levelField = $this->levelField;
        /*
         * Make a hypothetical formerItem which has the $prt lft and rgt value but it's child's level
         * moveAfter the hypothetical formerItem
         */
        $prtItem = $this->db
            ->clearQuery()
            ->select("$this->lftField,$this->rgtField,$this->levelField")
            ->from($this->table)
            ->where([$this->idField => $prt])
            ->find();
        $formerItem = new stdClass();
        $formerItem->$lftField = $prtItem->$lftField - 1;
        $formerItem->$rgtField = $prtItem->$lftField;
        $formerItem->$levelField = $prtItem->$levelField + 1;
        return $this->moveAfter($src, null, $formerItem);
    }

    /**
     * Method to move $src to a parent item ,$prt
     * And it is move as the last child of the parent
     * @param int $src Id of source item
     * @param int $prt Id of destination parent item
     */
    public function moveAppend($src, $prt)
    {
        /**
         * Case 1 :
         *      There is a child,then just find the child and moveAfter the last child
         * Case 2:
         *      There is no child,then just need to movePrepend
         */
        $idField = $this->idField;

        $childItem = $this->db
            ->clearQuery()
            ->select("c.$this->idField")
            ->from("$this->table AS p")
            ->join("$this->table AS c ON c.$this->rgtField=p.$this->rgtField-1 AND c.$this->levelField=p.$this->levelField+1")
            ->where("p.$this->idField=$prt")
            ->order("c.$this->idField DESC")
            ->find();
        if (empty($childItem->$idField)) {
            // Case 2
            $this->movePrepend($src, $prt);
        } else {
            // Case 1
            $this->moveAfter($src, $childItem->$idField);
        }
    }

    /**
     * Delete every thing form given id, and id referred record included
     * if you just want to delete it's descendants, but the id referred record, use deleteDescendants() instead
     * @param $id
     * @return bool
     */
    public function delete($id)
    {
        $this->_checkExists($id);

        $lftField = $this->lftField;
        $rgtField = $this->rgtField;

        $db = $this->db;
        $item = $db->clearQuery()
            ->select("$this->lftField,$this->rgtField,$this->levelField")
            ->from($this->table)
            ->where([$this->idField => $id])
            ->find();

        // Delete
        $db->clearQuery()
            ->delete($this->table)
            ->where([$this->idField => $id])
            ->where("(`$this->lftField`>{$item->$lftField} AND `$this->rgtField`<{$item->$rgtField})", 'OR');
        $db->execute();
        // Update
        $diff = $item->$rgtField - $item->$lftField + 1;
        $db->clearQuery()
            ->update($this->table, "`$this->rgtField`=`$this->rgtField`-$diff")
            ->where("`$this->rgtField`>{$item->$rgtField}")
            ->execute();
        $db->clearQuery()
            ->update($this->table, "`$this->lftField`=`$this->lftField`-$diff")
            ->where("`$this->lftField`>{$item->$rgtField}")
            ->execute();
        return true;
    }

    /**
     * Delete all descendants
     * @param int $parentId
     * @return boolean $this
     */
    public function deleteDescendants($parentId)
    {
        $item = $this->getItemById($parentId);
        $lftValue = $item[$this->lftField];
        $rgtValue = $item[$this->rgtField];
        /* Delete */
        $this->db->clearQuery()
            ->delete($this->table)
            ->where(1)
            ->andWhere([
                [$this->lftField, '>', $lftValue],
                [$this->rgtField, '<', $rgtValue]
            ])
            ->execute();
        $diff = $rgtValue - $lftValue - 1;
        /* Update right value */
        $this->db->clearQuery()->where(1)
            ->andWhere([
                [$this->rgtField, '>', $lftValue]
            ])
            ->update($this->table, [
                new Expression("$this->rgtField=$this->rgtField-$diff")
            ])
            ->execute();
        /* Update left value*/
        $this->db->clearQuery()->where(1)
            ->andWhere([
                [$this->lftField, '>', $lftValue]
            ])
            ->update($this->table, [
                new Expression("$this->lftField=$this->lftField-$diff")
            ])
            ->execute();
        return true;
    }

    /**
     * Set order , default to be `lft` ASC
     * You can pass param as follows <br>
     * 1, ->orderBy('ASC') [default] OR ->orderBy('DESC') as `lft` DESC
     * 2, ->orderBy(order by SQL statement), a.k.a ->orderBy('id DESC');
     * @param string $order
     * @return $this
     */
    public function orderBy($order = 'ASC')
    {
        if ($order) {
            $this->order = in_array(strtoupper($order), ['DESC', 'ASC']) ? "`$this->lftField` $order": $order;
        }
        return $this;
    }

    /**
     * Get order by
     * @return string
     */
    protected function getOrderBy()
    {
        if ($this->order) {
            return $this->order;
        } else {
            return $this->orderBy()->getOrderBy();
        }
    }

    /**
     * Find siblings, who does not include itself
     * @param int $parent
     * @param array $fields [field1, field2]
     * @param mixed $condition Filter condition for siblings<br>
     * e.g.<br>
     * ->siblings(1, ['name', 'sex'], 'sex="male"')
     * <br>
     * <p>OR</p>
     * ->siblings(1, ['name', 'sex'], ['sex' => 'male'])
     * @return array Rows found, empty array if not found
     */
    public function siblings($parent, $fields = [], $condition = null)
    {
        $fields = array_merge($this->getBaseFields(), is_array($fields) ? $fields : []);
        $parent = is_numeric($parent) ? $this->parent($parent, []) : $parent;
        $query = $this->db->clearQuery()->select($fields)->from($this->table)->where(1);
        if (!$parent) { //it's the very top
            return [];
        } else {
            $query->andWhere([
                $this->levelField => $parent[$this->levelField] + 1,
                [$this->lftField, '>', $parent[$this->lftField]],
                [$this->rgtField, '<', $parent[$this->rgtField]],
                [$this->idField, '!=', $parent[$this->idField]],
            ]);
        }
        if ($condition) {
            $query->andWhere($condition);
        }
        $query->order($this->getOrderBy());
        $rows = $query->execute();
        if ($rows) {
            array_walk($rows, function (&$v) {
                /* @var $v Record */
                $v = $v->asArray();
            });
        }
        return $rows;
    }

    /**
     * Find all children and it's descendants recursively
     * @param array|int $parent
     * @param array $fields
     * @param mixed $condition
     * @return array
     * @throws Exception
     */
    public function descendants($parent, $fields = [], $condition ='')
    {
        $parent = is_numeric($parent) ? $this->getItemById($parent): $parent;
        $fields = array_merge($this->getBaseFields(), $fields);
        $query = $this->db->clearQuery()
            ->from($this->table)
            ->select($fields)
            ->where(1)
            ->andWhere([
                [$this->lftField, '>', $parent[$this->lftField]],
                [$this->rgtField, '<', $parent[$this->rgtField]],
            ]);
        if ($condition) {
            $query->andWhere($condition);
        }
        $query->order($this->getOrderBy());
        $rows = $query->execute();
        if (is_array($rows)) {
            array_walk($rows, function (&$v) {
                /* @var $v Record */
                $v = $v->asArray();
            });
        }
        return $rows;
    }

    /**
     * Find all direct children,
     * @param array|int $parent
     * @param array $fields
     * @param mixed $condition
     * @return array
     */
    public function children($parent, $fields = [], $condition ='')
    {
        $parent = is_numeric($parent) ? $this->getItemById($parent) : $parent;
        if ($condition) {
            $condition = is_string($condition) ? [$condition] : $condition;
        } else {
            $condition = [];
        }
        $condition[] = [$this->levelField => $parent[$this->levelField] + 1];

        return $this->descendants($parent, $fields, $condition);
    }

    /**
     * Find direct parent of given id
     * @param $id
     * @param array $fields Defines extra fields other than base fields that needed to be returned
     * @param string $condition
     * @return array Empty array will be returned if no parent found
     */
    public function parent($id, $fields = [], $condition = '')
    {
        $fields = $this->prependFieldsTable('p', array_merge($this->getBaseFields(), $fields));
        $query = $this->db->clearQuery()
            ->select($fields)
            ->from("$this->table AS p")
            ->join("$this->table AS c ON c.$this->lftField>p.$this->lftField AND c.$this->rgtField<p.$this->rgtField AND c.$this->levelField=p.$this->levelField+1")
            ->where("c.$this->idField=$id");
        if ($condition) {
            $query->andWhere($condition);
        }
        $parent = $query->find();
        return $parent[$this->idField] ? $parent : [];
    }

    /**
     * Get base fields name, according to setNames
     * @return array
     */
    protected function getBaseFields()
    {
        $fields = [$this->idField, $this->levelField, $this->lftField, $this->rgtField];
        return $fields;
    }

    /**
     * Return columns with prefix, mainly used to add table prefix with given alias
     * @param string $table
     * @param array $fields Origin table array
     * @return array Alias prefixed array e.g. ["p.id", "p.name]
     */
    protected function prependFieldsTable($table, $fields)
    {
        array_walk($fields, function (&$v, $k, $table) {
            $v = "`$table`.`$v`";
        }, $table);
        return $fields;
    }

    private function setNewItem($insertArray)
    {
        $db = $this->db;
        $db->clearQuery();
        return $db->insert($this->table, $insertArray);
    }

    private function _checkExists($id)
    {
        $this->db->clearQuery();
        $count = $this->db->from($this->table)->where([$this->idField => $id])->count();
        if (0 === $count) {
            throw new Exception('Invalid current item given');
        }
    }

    /**
     * Method to check if it's the the relationship is parent-child,or even grand kind of relationship
     * @param int $src Id of source item
     * @param int $prt Id of destination parent item
     * @return bool If $prt is parent of $src already
     * @throws Exception
     */
    protected function isParents($src, $prt)
    {
        if (empty($src)) {
            throw new Exception('Source id cannot be empty');
        }
        if (empty($prt)) {
            throw new Exception('Source id cannot be empty');
        }
        if ($src == $prt) {
            return false;
        }
        $db = $this->db;
        $db->clearQuery();
        $db->select(new Expression("DISTINCT p.$this->idField"))
            ->from("$this->table AS d")
            ->join("$this->table AS p ON p.$this->lftField<d.$this->lftField AND p.$this->rgtField>d.$this->rgtField")
            ->where("`d`.`$this->idField`=$src")
            ->where("`d`.`$this->idField`=$prt", 'OR');
        $pids = $db->execute(null, false);
        foreach ($pids as $pid) {
            if ($pid[$this->idField] == $src || $pid[$this->idField] == $prt) {
                return true;
            }
        }
        return false;
    }

    /**
     * Method to check $later is accually later than $former
     * @param int $former Id of the former item
     * @param int $later Id of the later item
     * @return boolean If succeeded
     */
    private function isRightBefore($former, $later)
    {
        $idField = $this->idField;
        $laterItem = $this->db
            ->clearQuery()
            ->select("l.$this->idField")
            ->from("$this->table AS f")
            ->join("$this->table AS l ON f.$this->rgtField=l.$this->lftField-1 AND f.$this->levelField=l.$this->levelField")
            ->where(["f.$this->idField" => $former])
            ->find();
        if (empty($laterItem[$idField])) {
            return false;
        }
        return $later == $laterItem->$idField ? true : false;
    }

    /**
     * @param $id
     * @param array $fields
     * @return Record
     * @throws Exception
     */
    protected function getItemById($id, $fields = [])
    {
        $fields = array_merge($this->getBaseFields(), $fields);
        $item = $this->db->clearQuery()
            ->select($fields)
            ->from($this->table)
            ->where([$this->idField => $id])
            ->find();
        if (empty($item[$this->idField])) {
            throw new Exception('Invalid id is given');
        } else {
            return $item;
        }
    }

    protected function create($data)
    {
        $columns = array_merge(array(
            $this->lftField => $this->veryLft,
            $this->rgtField => $this->veryLft + 1,
            $this->levelField => $this->veryLevel,
        ), $data);

        return $this->db->clearQuery()->insert($this->table, $columns);
    }

    public function show($name = 'role_name')
    {

        $levelField = $this->levelField;
        $idField = $this->idField;
        $items = $this->db->clearQuery()
            ->select('*')
            ->from($this->table)
            ->order("`$this->lftField` ASC")
            ->execute();

        if (empty($items)) {
            return false;
        }
        echo '<ul style="list-style: none;">';
        foreach ($items as $item) {
            echo '<li>';
            echo str_repeat('<span class="gi">|&mdash;</span>', $item->$levelField);
            echo $item->$name . '(' . $item->$idField . ')';
            echo '</li>';
        }
        echo '</ul>';
    }

}
