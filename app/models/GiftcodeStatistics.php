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

    private $log;
    public function __construct() {
        $this->log = app()->logger();
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
}
