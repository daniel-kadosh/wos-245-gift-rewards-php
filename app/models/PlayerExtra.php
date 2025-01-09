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
    private $giftcode_ids = '';   // Don't store this in the DB field "extra"

    const F_STRING   = 1;
    const F_INT      = 2;
    const F_ARRAY    = 3;
    const F_ALLIANCE = 4; // "join" with alliance_id from database
    const F_BOOLEAN  = 6;

    const GC_DELIMITER = '@';

    private $log;
    private $fields = [
        'alliance_id'   => self::F_ALLIANCE,
        'comment'       => self::F_STRING,
        'ignore'        => self::F_BOOLEAN,
        'giftcode_ids'  => self::F_STRING
    ];
    private $alliances; // Valid values for alliance_id & name

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
            case self::F_ARRAY:
                return ''; // ignore for now...
            default:
                return "Unknown field type $type for field $field";
        }
        // Drop-down selection:
        $ret = sprintf('<select name="%s" id="%s%s">',
            $field, ($isFilter ? 'f:' : ''), $field );
        $targetID = (isset($options[$this->$field]) ? $this->$field : 0);
        foreach ($options as $id => $name) {
            $ret .= sprintf( '<option value="%d"%s>%s</option>',
                        $id,($id==$targetID ? ' selected' : ''),$name );
        }
        $ret .= '</select>';
        return $ret;
    }

    /**
     * Set or replace object values
     * @param string $extra     JSON string stored in 'extra' DB column
     */
    public function parseJsonExtra($extra,$giftCodeIDs=null) {
        // First reset everything to defaults
        foreach ($this->fields as $field => $type) {
            $this->$field = ($type==self::F_STRING ? '' :
                            ($type==self::F_ARRAY  ? [] : 0) );
        }
        if (empty($extra)) {
            return;
        }
        try {
            $x = json_decode($extra);
            foreach ($x as $name => $value) {
                $type = $this->fields[$name];
                $this->$name = ($type==self::F_STRING ? trim($value) :
                               ($type==self::F_ARRAY  ? $value       : (int) $value) );
            }
        } catch (\Exception $e) {
            $this->log->info(__METHOD__.' Exception: '.$e->getMessage());
            $this->log->info('extra='.$extra);
        }
        if ( !empty($giftCodeIDs) ) {
            $this->giftcode_ids = $giftCodeIDs;
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
            $a[$field] = ($type!=self::F_INT ? $this->$field : (int) $this->$field);
        }
        return $a;
    }

    // Special field functions
    public static function delimitGiftCodeID($giftCodeID) {
        // Single place in code where we define delimiters for a string of IDs
        return self::GC_DELIMITER.$giftCodeID.self::GC_DELIMITER;
    }
    public function setGiftcodeIDs($gids) {
        $this->giftcode_ids = $gids;
    }
    public function getGiftcodeIDs() {
        return $this->giftcode_ids;
    }
    public function hasGiftcodeID($giftCodeID) {
        return strstr($this->giftcode_ids,self::delimitGiftCodeID($giftCodeID)) ? true : false;
    }
    public function addGiftcodeID($giftCodeID) {
        if ( ! $this->hasGiftcodeID($giftCodeID) ) {
            $gids = $this->giftcode_ids.self::delimitGiftCodeID($giftCodeID);
            $this->giftcode_ids = str_replace(self::GC_DELIMITER.self::GC_DELIMITER,
                    self::GC_DELIMITER,
                    $gids);
            return true;
        }
        return false;
    }
}
