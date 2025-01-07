<?php

namespace App\Models;

use Exception;

/**
 * Manage giftcode statistics
 *
 * This is a private object model, not based on Leaf/Eloquent's model
 */
class GiftcodeStatistics {
    public $usersSending       = [];
    public $expected           = [];
    public $succesful          = 0;
    public $alreadyReceived    = 0;
    public $hitRateLimit       = 0;
    public $networkError       = 0;
    public $signinErrorCodes   = [];
    public $giftErrorCodes     = [];
    public $deletedPlayers     = [];
    public $runtime            = 0;

    // States for GiftCode
    const GC_QUEUED     = 1;
    const GC_RUNNING    = 2;
    const GC_QUIT       = 3;
    const GC_DONE       = 4;
    const GC_EXPIRED    = 5;

    private $log;
    public function __construct($statisticsJSON = null) {
        $this->log = app()->logger();
        if ( ! is_null($statisticsJSON) ) {
            $this->parseJsonStatistics($statisticsJSON);
        }
    }

    /**
     * Increment the value of one of the array statistics
     */
    public function increment(string $varName, string $key) {
        try {
            if ( empty($this->$varName[$key]) ) {
                $this->$varName[$key] = 1;
            } else {
                $this->$varName[$key]++;
            }
        } catch (Exception $e) {
            $this->log->info(__METHOD__." varName=$varName Exception: ".$e->getMessage());
        }
    }

    /**
     * Set or replace object values
     * @param string $statistics     JSON string stored in 'statistics' DB column
     */
    public function parseJsonStatistics($statistics) {
        // First reset everything to defaults
        foreach ($this as $field => $value) {
            if ( !is_object($value) ) {
                $this->$field = (is_array($value) ? [] : 0);
            }
        }
        if (empty($statistics)) {
            return;
        }
        try {
            $x = json_decode($statistics, JSON_OBJECT_AS_ARRAY);
            foreach ($x as $name => $value) {
                $this->$name = $value;
            }
        } catch (Exception $e) {
            $this->log->info(__METHOD__.' Exception: '.$e->getMessage());
            $this->log->info('statistics='.$statistics);
        }
    }

    /**
     * Returns JSON-encoded string of giftcodeStatistics object
     */
    public function getJson() {
        return json_encode($this, JSON_UNESCAPED_UNICODE | JSON_OBJECT_AS_ARRAY);
    }

    public static function stateOfGiftCode( &$giftCodeObject ) {
        if ( $giftCodeObject['pct_done']==-1 && $giftCodeObject['send_gift_ts']==0 ) {
            return self::GC_QUEUED;
        } else if ( $giftCodeObject['pct_done']==-2 ) {
            return self::GC_EXPIRED;
        } else if ( $giftCodeObject['pct_done']<100 && $giftCodeObject['send_gift_ts']>0 ) {
            return self::GC_RUNNING;
        } else if ( $giftCodeObject['pct_done']<100 && $giftCodeObject['send_gift_ts']==0 ) {
            return self::GC_QUIT;
        } else if ( $giftCodeObject['pct_done']==100 ) {
            return self::GC_DONE;
        }
        return 0; // Undefined?
    }
    public function stateOfGiftCodeHTML( &$giftCodeObject, $withJobTS=false) {
        $stateFormat = '<b><span style="color:%s">%s</span></b>';
        $pctDone = ' %d%% done';
        $jobTS = $withJobTS ? "</br>".gmdate("Y-m-d H:i:s",$giftCodeObject['send_gift_ts']) : '';
        switch ( self::stateOfGiftCode($giftCodeObject) ) {
            case self::GC_QUEUED:     // 1) hasn't started
                $msg = sprintf($stateFormat.$pctDone,'#007000','QUEUED',0);
                #$this->p("QUEUED $updateHMS ago. Hasn't started processing, expecting $numPlayers players.",'p',true);
                break;
            case self::GC_RUNNING:    // 2) still processing
                $msg = sprintf($stateFormat.$pctDone.$jobTS,'#007000','RUNNING',$giftCodeObject['pct_done']);
                #$this->p("RUNNING for $startHMS, $pctDone done, at $numPlayers/$origNumPlayers players.",'p',true);
                break;
            case self::GC_QUIT:       // 3) Quit mid-process, restart if users to process
                $msg = sprintf($stateFormat.$pctDone,'#700000','QUIT',$giftCodeObject['pct_done']);
                #$msg = "QUIT last run at $pctDone done for $origNumPlayers players";
                break;
            case self::GC_DONE:       // 4) Already done, restart if users to process
                $msg = sprintf($stateFormat.$pctDone,'#000070','SENT',100);
                #$msg = 'FULLY COMPLETED previously';
                break;
            case self::GC_EXPIRED:       // 4) Already done, restart if users to process
                $msg = sprintf($stateFormat,'#000070','EXPIRED');
                #$this->p("EXPIRED or invalid gift code as of last check on $updateHMS.",'p',true);
                break;
            default:
                $msg = sprintf($stateFormat,'#A00000','UNKNOWN');
                break;
        }
        return $msg;
    }
}
