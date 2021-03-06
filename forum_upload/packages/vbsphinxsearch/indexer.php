<?php
if (!defined('VB_ENTRY'))
    die('Access denied.');
require_once(DIR . '/vb/search/itemindexer.php');

/**
 * Indexer for Sphinx Search engine.
 *
 * Main index dosen't need special actions, but for Delta index need queue.
 * This class write document id to queue table.
 *
 */
class vBSphinxSearch_Indexer extends vB_Search_ItemIndexer
{

    protected $_id_list;

    protected $_content_type_id;

    /**
     * Push single item to index queue.
     *
     * @param array $fields - list of item colums names & values
     *
     * Params format - from generic vB class. We use only 2:
     *   - primaryid (or "id", depends on item type)
     *   - contenttypeid
     */
    public function index($fields)
    {
        if (array_key_exists('id', $fields) AND !(array_key_exists('primaryid', $fields)))
        {
            $fields['primaryid'] = $fields['id'];
            unset($fields['id']);
        }
        if (!array_key_exists('primaryid', $fields) OR !array_key_exists('contenttypeid', $fields))
        {
            $message = 'Document ID or Content Type not specified for indexer. Request aborted.';
            vBSphinxSearch_Core::log_errors($message);
            return false;
        }

        $this->_content_type_id = $fields['contenttypeid'];
        $this->_id_list = array($fields['primaryid']);
        $this->_write_to_queue();
        return true;
    }

    /**
     * Mark record as deleted
     *
     * @param int $contenttype
     * @param int $id
     */
    public function delete($content_type_id, $id)
    {
        $this->_content_type_id = $content_type_id;
        $this->_id_list = array($id);
        return $this->_write_to_queue();
    }

    /**
     * Added for compatibility with original search engine. Called
     * on merge threads/discussions
     * 
     * Note, that merge operations are very rare, and full index rebuild
     * goes every day. So, we don't need to be vary careful here. Override
     * method in child controller, if needed.
     */
    public function merge_group($content_type_id, $old_group_id, $new_group_id)
    {
        $message = 'Index controller must be use';
        vBSphinxSearch_Core::log_errors($message);
        return false;
    }

    /**
     * In theory, sould be called every time when title/info should be updated.
     * For example, on every new post we should refresh topic info.
     * That's a bit expensive.
     *
     * But we use optimised code, and don't call this function at all.
     * It's left for compatibility, if someone in vB add new index controller
     * with direct all.
     * 
     * Params - contenttypeid & groupid
     */
    public function group_data_change($fields)
    {
        $message = 'Function group_data_change not optimized and should not be called directly in ' .
                    vB_Types::instance()->getContentTypeTitle($fields['contenttypeid']) .
                    ' index controller. See vBSphinxSearch_Search_IndexController_Post for example.';
        vBSphinxSearch_Core::log_errors($message);
        
        $this->_content_type_id = $fields['contenttypeid'];
        if ($this->_fetch_object_id_list($fields['groupid'], true))
        {
            return $this->_write_to_queue();
        }
        return false;
    }

    /**
     * Used for mass-delete posts by topic id / discussion id etc.
     * 
     * Left for compatibility. This code is optimized in index controllers,
     * no direct calls done.
     */
    public function delete_group($content_type_id, $group_id)
    {
        $message = 'Function delete_group not optimized and should not be called directly in ' .
                    vB_Types::instance()->getContentTypeTitle($fields['contenttypeid']) .
                    ' index controller. See vBSphinxSearch_Search_IndexController_Post for example.';
        vBSphinxSearch_Core::log_errors($message);

        $this->_content_type_id = $content_type_id;
        if ($this->_fetch_object_id_list($group_id))
        {
            return $this->_write_to_queue();
        }
        return false;
    }

    /**
     * Build reindex queue. Each document is (id,contenttype)
     * content type is taken from property. It should be pre-defined.
     * Doc ids passed as params.
     * 
     */
    protected function _write_to_queue()
    {
        if (!is_array($this->_id_list) OR empty($this->_id_list))
        {
            return false;
        }

        $indexes = vBSphinxSearch_Core::get_sphinx_index_map($this->_content_type_id);
        if (empty($indexes))
        {
            return false;
        }

        $db = vB::$vbulletin->db;
        $values = array();
        foreach ($this->_id_list as $id)
        {
            $values[] = '(' . $this->_content_type_id . ', ' . $id . ')';
        }

        $sql = 'INSERT INTO ' . TABLE_PREFIX . 'vbsphinxsearch_queue  (`contenttypeid`, `primaryid`)
                VALUES ' . implode(', ', $values) . '
                ON DUPLICATE KEY UPDATE
                    `contenttypeid` = VALUES(`contenttypeid`), `primaryid` = VALUES(`primaryid`), `done` = 0';
        return $db->query_write($sql);
    }

    /**
     * Fetch list of ids by group_id. (For example - all posts by thread id)
     * 
     * Attention(!) Selection by pure attributes is ineffective in sphinx.
     * There will be problems for mass threads rebuild.
     * 
     * This code is for compatibility reasons, if optimised version
     * not implementer in index controler
     */
    protected function _fetch_object_id_list($group_id, $only_first = false)
    {
        if (is_null($this->_content_type_id) OR 0 == (int) $group_id)
        {
            return false;
        }

        $indexes = implode(",", vBSphinxSearch_Core::get_sphinx_index_map($this->_content_type_id));
        if (empty($indexes))
        {
            return false;
        }

        $limit = vBSphinxSearch_Core::SPH_DEFAULT_RESULTS_LIMIT;
        
        $query = 'SELECT *
                    FROM 
                        ' . $indexes . '
                    WHERE
                        contenttypeid = ' . $this->_content_type_id . ' AND
                        groupid = ' . $group_id . ($only_first ? ' AND isfirst = 1' : '') . '
                    LIMIT ' . $limit . ' OPTION max_matches=' . $limit;

        $this->_id_list = array();
        for ($i = 0; $i < vBSphinxSearch_Core::SPH_RECONNECT_LIMIT; $i++)
        {
            $con = vBSphinxSearch_Core::get_sphinxql_conection();
            if (false != $con)
            {
                $result_res = mysql_query($query, $con);
                if ($result_res)
                {
                    while ($docinfo = mysql_fetch_assoc($result_res))
                    {
                        $this->_id_list[] = $docinfo['primaryid'];
                    }
                    return true;
                }
            }
        }
        $error = mysql_error();
        $message = "\n\nSphinx: Can't get primaryid list for groupid=$group_id\nUsed indexes: $indexes\n Error:\n$error\n";
        vBSphinxSearch_Core::log_errors($message);

        return false;
    }
}
