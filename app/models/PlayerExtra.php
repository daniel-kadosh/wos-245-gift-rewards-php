<?php

namespace App\Models;

/**
 * Manage a player's "extra" field, with data outside of WOS
 *
 * This is a private object model, not based on Leaf/Eloquent's model
 */
class PlayerExtra {
    public $alliance_id = 0;
    public $comment     = '';
    public $ignore      = 0;
    #public $rank        = 0;
    #public $power;

    const F_STRING   = 1;
    const F_INT      = 2;
    const F_RANK     = 3; // numbers 1-5
    const F_ALLIANCE = 4; // "join" with alliance_id from database
    const F_BOOLEAN  = 6;

    private $log;
    private $fields = [
        'alliance_id'   => self::F_ALLIANCE,
        'comment'       => self::F_STRING,
        'ignore'        => self::F_BOOLEAN
        #'rank'          => self::F_RANK
        #'power'         => self::F_INT
    ];
    private $alliances; // Valid values for alliance_id & name
    private $ranks;     // Valid values for rank (R1-R5)

    /**
     * @param string $extra     Optional JSON string stored in 'extra' DB column
     * @param array  $getAlliances Optional boolean to get alliances from the database
     */
    public function __construct(string $extra='', $getAlliances=false) {
        $this->log = app()->logger();
        $this->parseJsonExtra($extra);

        // Pre-populate drop-down fields with valid values
        $this->alliances[0] = '-';
        if ($getAlliances) {
            $alliances = db()
                ->select('alliances',"id,'[' || short_name || ']' || long_name as alliance_name")
                ->all();
            foreach ($alliances as $a) {
                $this->alliances[$a['id']] = $a['alliance_name'];
            }
        }
        /*
        $this->ranks[0] = '-';
        for ($i=1; $i<6; $i++) {
            $this->ranks[$i] = "R$i";
        }
        */
    }

    /**
     * Returns the HTML of a form field to display current value + input
     */
    public function getHtmlForm($field,$isFilter=false) {
        $type = ! isset($this->fields[$field]) ? self::F_STRING : $this->fields[$field];
        switch ($type) {
            case self::F_ALLIANCE:
                $options = $isFilter ?
                    [-1 => 'all'] + $this->alliances :
                    $this->alliances;
                break;
            #case self::F_RANK:
            #    $options = &$this->ranks;
            #    break;
            case self::F_INT:
                return sprintf('<input type="number" id="%s" name="%s" value="%d">',
                            $field,$field,$this->$field);
            case self::F_STRING:
                return sprintf('<input type="text" id="%s" name="%s" size="%d" value="%s">',
                            $field,$field,($field=='comment' ? 30 : 10),$this->$field);
            case self::F_BOOLEAN:
                if (!$isFilter) {
                    return sprintf('<input type="checkbox" id="%s" name="%s" %s/>',
                            $field,$field,($this->$field ? 'checked ' : ''));
                }
                $options = [-1 => 'all', 0 => 'false', 1=> 'true'];
                break;
            default:
                return "Unknown field type $type for field $field";
        }
        // Drop-down selection:
        $ret = sprintf('<select name="%s" id="%s%s">',
            $field, ($isFilter ? 'f:' : ''), $field );
        $targetId = (isset($options[$this->$field]) ? $this->$field : 0);
        foreach ($options as $id => $name) {
            $ret .= sprintf( '<option value="%d"%s>%s</option>',
                        $id,($id==$targetId ? ' selected' : ''),$name );
        }
        $ret .= '</select>';
        return $ret;
    }

    /**
     * Set or replace object values
     * @param string $extra     JSON string stored in 'extra' DB column
     */
    public function parseJsonExtra($extra) {
        // First reset everything to defaults
        foreach ($this->fields as $field => $type) {
            $this->$field = ($type==self::F_STRING ? '' : 0);
        }
        if (empty($extra)) {
            return;
        }
        try {
            $x = json_decode($extra);
            foreach ($x as $name => $value) {
                $type = $this->fields[$name];
                $this->$name = ($type==self::F_STRING ? trim($value) : (int) $value);
            }
        } catch (\Exception $e) {
            $this->log->info(__METHOD__.' Exception: '.$e->getMessage());
            $this->log->info('extra='.$extra);
        }
    }

    /**
     * Returns JSON-encoded string of playerExtra object
     */
    public function getJson() {
        return json_encode($this, JSON_UNESCAPED_UNICODE);
    }
    /**
     * Get public properties as an array
     * @param includeHidden Optional boolean to include hidden fields
     */
    public function getArray($includeHidden=false) {
        $a = [];
        if ($includeHidden) {
            $a['alliance_name'] = $this->alliances[ intval($this->alliance_id) ];
        }
        foreach ($this->fields as $field => $type) {
            $a[$field] = ($type==self::F_STRING ? $this->$field : (int) $this->$field);
        }
        return $a;
    }
}
