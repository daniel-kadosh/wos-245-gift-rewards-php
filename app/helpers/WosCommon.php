<?php

namespace App\Helpers;

use App\Models\PlayerExtra;
use Exception;
use GuzzleHttp\Client;
use Leaf\Config;
use Leaf\Log;
use PDOException;

class WosCommon
{
    const HASH          = "tB87#kPtkxqOS2"; // WOS API secret

    public $time = null;   // tick() DateTime object
    public $stats;         // giftCodeStatistics object
    public $log;           // Leaf logger
    public $dbg;           // boolean: true if APP_DEBUG for verbose logging
    public $guzEmulate;    // boolean: true if GUZZLE_EMULATE to not make WOS API calls
    public $badResponsesLeft;  // Number of questionable bad responses from WOS API before abort
    public $baseDataDir;   // For a number of files used by the app or Apache
    public $dataDir;       // For a number of files used by the app or Apache

    public $host2Alliance;
    public $alliance2Long;
    public $ourState;       // WOS state number
    public $ourAlliance;    // 3-letter alliance name
    public $ourAllianceLong;

    public $webMode;        // Whether running as a webpage or command-line
    public $myPID;

#    private $con;
#    public function __construct($controllerObject) {
#        $this->con = $controllerObject;
    public function __construct() {
        $this->myPID = posix_getpid();
        $callingClass = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT|DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]['class'];
        $this->webMode = ( strstr($callingClass,'App\Console') ? false : true );

        // Determine OUR_ALLIANCE from URL hostname (1st string in FQDN)
        $alliances      = explode(',',_env('ALLIANCES',     'VHL'));      // 3-letter names
        $alliancesLong  = explode(',',_env('ALLIANCES_LONG','Valhalla')); // long names
        $this->ourState = _env('OUR_STATE', 245);

        for ($i=0; $i<count($alliances); $i++) {
            $this->host2Alliance[$alliances[$i]] = [
                strtolower($alliances[$i]),
                strtolower($alliances[$i]).$this->ourState,
                strtolower($alliancesLong[$i]),
                strtolower($alliancesLong[$i]).$this->ourState,
            ];
            $this->alliance2Long[$alliances[$i]] = $alliancesLong[$i];
        }

        // Pull environment variables from .env file, with some default settings if not found
        $this->dbg          = strcasecmp(_env('APP_DEBUG',''),     'true') == 0;
        $this->guzEmulate   = strcasecmp(_env('GUZZLE_EMULATE',''),'true') == 0;
        $this->baseDataDir  = _env('BASE_DATA_DIR', __DIR__.'/../../wos245');
    }

    public function setAllianceState($ourAlliance,$state=null) {
        $this->ourAlliance      = $ourAlliance;
        $this->ourAllianceLong  = $this->alliance2Long[$ourAlliance];
        $this->ourState         = ( empty($state) ? _env('OUR_STATE', 245) : $state );
        $this->dataDir          = sprintf('%s-%s/',$this->baseDataDir, strtolower($ourAlliance));

        // Set up logger
        if ( empty($this->log) ) {
            Config::set('log.dir', $this->webMode ? $this->dataDir : $this->baseDataDir.'/');
            Config::set('log.style','linux');
            $baseLogfile = ( $this->webMode ? 'wos_controller_' : 'giftcoded_' );
            Config::set('log.file', $baseLogfile.substr($this->getTimestring(false,false),0,7).'.log');
            $this->log = app()->logger();
            $this->log->level( $this->dbg ? Log::DEBUG : Log::INFO );
            $this->log->enabled(true);
        }

        $dbfile = $this->dataDir.'gift-rewards.db';
        putenv("DB_DATABASE=$dbfile");
        db()->autoConnect();
    }

    public function getTimestring($renew=false,$inUnixTime=true) {
        if (empty($this->time) || $renew) {
            $this->time = tick('now');
        }
	    return (string) $this->time->format($inUnixTime ? 'U':
                'YYYY-MM-DD HH:mm:ss');
    }

    public function verifyPlayerInWOS( &$p ) {
        // Verify player
        $this->p('<p>'.$p['id'].' - <b>'.$p['player_name'].'</b>: ',0,true);
        $tries = 3;
        $signInGood = false;
        $sleepAmount = 0;
        while ($tries>0 && $this->badResponsesLeft>0) {
            sleep($sleepAmount);
            $sleepAmount = 0;
            $tries--;
            $signInResponse = $this->signInWOS($p['id']);
            if ($this->dbg) {
                $this->pDebug('signInResponse= ',$signInResponse);
            }
            if (empty($signInResponse['http-status'])) {
                // Timeout or network error
                $this->stats->networkError++;
                $this->p('(Network error: '.$signInResponse['guzExceptionMessage'].') ',0,true);
                $this->badResponsesLeft--;
                sleep($this->guzEmulate ? 0 : 2);
            } else if ($signInResponse['http-status']==429) {
                // Hit rate limit!
                $this->stats->hitRateLimit++;
                $sleepAmount = $this->guzEmulate ? 1 : 61;
                $this->p("(Pausing $sleepAmount sec due to 429 signIn rate limit) ",0,true);
            } else if ($signInResponse['http-status'] >= 400) {
                $this->stats->increment('signinErrorCodes','GuzException '.$signInResponse['guzExceptionMessage']);
                $this->p(sprintf('<b>ABORT: WOS signIn API ERROR:</b> httpCode=%s Message=%s',
                    $signInResponse['http-status'], $signInResponse['guzExceptionMessage'] ) ,'p',true);
                return null;
            } else {
                // All good!
                $signInGood = true;
                break;
            }
        }
        if ( ! $signInGood ) {
            // Couldn't sign in above, but don't want to rush to remove
            // player unless we have positive confirmation they don't exist.
            $this->p('<b>ERROR:</b> Failed to sign in player</p>',0,true);
            return null;
        }
        $sd = $signInResponse['data'];
        $signInResponse['playerGood'] = true;
        $stateID = isset($sd->kid) ? $sd->kid : -1;
        if ($signInResponse['err_code'] == 40004 || $stateID !=$this->ourState ) {
            // 40004 = Player doesn't exist
            $this->p(sprintf('DELETING player: invalid %s</p>',
                            $stateID==-1 ? 'WOS user' : 'state (#'.$stateID.')'
                        ),0,true);
            if ( $this->deleteById('players',$p['id']) == -1 ) {
                // Exception thrown during delete, so let's just stop
                return null;
            }
            $this->stats->deletedPlayers[ $p['id'] ] = $p['player_name'];
            $signInResponse['playerGood'] = false;
        } else if (
            $p['player_name']       != trim($sd->nickname)  ||
            $p['avatar_image']      != $sd->avatar_image    ||
            $p['stove_lv']          != $sd->stove_lv        ||
            $p['stove_lv_content']  != $sd->stove_lv_content   )
        {
            // Update player if needed
            $data = [
                    'player_name'       => trim($sd->nickname),
                    'avatar_image'      => $sd->avatar_image,
                    'stove_lv'          => $sd->stove_lv,
                    'stove_lv_content'  => $sd->stove_lv_content,
                    'updated_at'        => $this->getTimestring(false,false)
                    ];
            $p = array_merge($p, $data);
            db()->update('players')
                ->params($data)
                ->where(['id' => $p['id']])
                ->execute();
        }
        return $signInResponse;
    }

    public function send1Giftcode($playerId,$giftCode) {
        // Won't check first that player hasn't received gift code, as that
        // should happen before calling this OR we need to resend to player

        // Begin retry loop
        $tries = 3;
        $sendGiftGood = false;
        $sleepAmount = 0;
        while ($tries>0 && $this->badResponsesLeft>0) {
            if ( ! $this->guzEmulate ) {
                // Only sleep at top of loop for retrying
                sleep($sleepAmount);
            }
            $sleepAmount = 0;
            $tries--;
            $giftResponse = $this->sendGiftCodeWOS($playerId,$giftCode);
            if ($this->dbg) {
                $this->pDebug('giftResponse= ',$giftResponse);
            }
            if (empty($giftResponse['http-status'])) {
                // Timeout or network error
                $this->stats->networkError++;
                $giftResponse['msg'] = $giftResponse['guzExceptionMessage'];
                $sleepAmount = $this->guzEmulate ? 0 : 2;
                $this->p('(Network error: '.$giftResponse['msg']." - pause $sleepAmount sec.) ",0,true);
                $this->badResponsesLeft--;
                continue; // Retry
            }
            $giftErrCode = $giftResponse['err_code'];
            if ($giftErrCode == 40014) {
                // Invalid gift code
                $this->p('Aborting: Invalid gift code','b',true);
                $this->stats->increment('giftErrorCodes','40014 Invalid gift code');
                return null;
            }
            if ($giftErrCode == 40007) {
                // Expired gift code
                $this->p('Aborting: Gift code expired','b',true);
                $this->stats->increment('giftErrorCodes','40007 Gift code expired');
                return null;
            }
            $resetIn = 0;
            if ($giftErrCode == 40004) {
                // Timeout retry
                $resetIn = 3;
                $msg = "Gift errCode=$giftErrCode Timeout retry";
            } else if ($giftResponse['http-status']==429) {
                // Too many requests
                if ( !empty($giftResponse['headers']['x-ratelimit-reset']) ) {
                    $ratelimitReset = $giftResponse['headers']['x-ratelimit-reset'];
                    // Convert from UNIX time?
                    $resetAt = (intval($ratelimitReset) == $ratelimitReset ?
                                    tick("@$ratelimitReset") : tick());
                    $resetIn = intval($ratelimitReset) - intval($this->getTimestring(false,true));
                } else {
                    $ratelimitReset = -1;
                    $resetAt = tick();
                }
                // For sanity, until I see real values for x-ratelimit-reset
                if ( $resetIn < 1 || $resetIn > 65) {
                    $resetIn = 21;
                }
                //if ( $this->dbg ) {
                // Force debug info for this case, as we haven't seen this live.
                // The 60sec sleep for a 429 in signIn above seems to have solved
                // this whole issue, and we may not need to sleep here at all.
                    $this->pDebug('**** giftHeaders: ',$giftResponse['headers']);
                    $this->p("429: x-ratelimit-reset=$ratelimitReset"
                        ."\nnow=".$this->getTimestring(false,true)
                        ."=".$this->getTimestring(false,false)
                        ."\nresetIn=$resetIn"
                        ."\nresetAt=".$resetAt->format('YYYY-MM-DD HH:mm:ss')
                        ,'pre',true);
                //}
                $msg = "http 429 Too many attempts";
                $this->stats->hitRateLimit++;
            } else if ($giftResponse['http-status'] >= 400) {
                $this->p('<b>WOS gift API ERROR:</b> '.$giftResponse['guzExceptionMessage'],'p',true);
                $this->stats->increment('giftErrorCodes','GuzException '.$giftResponse['guzExceptionMessage']);
                return null;
            }
            if ( !empty($msg) ) {
                $this->stats->increment('giftErrorCodes',$msg);
            }
            if ( $resetIn > 0 ) {
                $msg = "$msg: ".$giftResponse['msg']." - pausing $resetIn sec.";
                $this->p("($msg)",0,true);
                $this->updatePlayerMessageGiftcodeID($playerId,null,$msg);
                $sleepAmount = $this->guzEmulate ? 1 : $resetIn;
            } else { // Success!
                break;
            }
        }
        switch ($giftErrCode) {
            case 20000:
                $msg = "$giftCode: redeemed succesfully";
                $sendGiftGood = true;
                $this->stats->succesful++;
                break;
            case 40008:
                $msg = "$giftCode: already used";
                $sendGiftGood = true;
                $this->stats->alreadyReceived++;
                break;
            case 40011: // Some other version of "already used"
                $msg = "$giftCode: used (SAME TYPE EXCHANGE)";
                $sendGiftGood = true;
                $this->stats->alreadyReceived++;
                break;
            default:
                $msg = "$giftErrCode ".$giftResponse['msg'];
                $this->stats->increment('giftErrorCodes',$msg);
                break;
        }
        $this->p("$msg</p>",0,true);
        $this->updatePlayerMessageGiftcodeID($playerId,
                ($sendGiftGood ? $this->getGiftcodeID($giftCode) : null),$msg);
        $this->updateGiftcodeStats($giftCode);

        if ( ! $sendGiftGood ) {
            // Unless we know for sure we should continue to other players,
            // let's abort here and not hit the API any more.
            // We can add more retriable cases above as we find them.
            $this->p('Cannot confirm we can continue, stopping now.','p',true);
            return null;
        }
        return $giftResponse;
    }

    ////////////////// Helper DB functions
    public function updateGiftcodeStats($giftCode,$sendGiftTS=null) {
        $params = [
            'updated_at' => $this->getTimestring(true,false),
            'statistics' => $this->stats->getJson()
        ];
        if ( !is_null($sendGiftTS) ) {
            $params['send_gift_ts'] = $sendGiftTS;
        }
        $rowsUpdated = 0;
        try {
            $rowsUpdated = db()
                    ->update('giftcodes')
                    ->params($params)
                    ->where(['code' => $giftCode])
                    ->execute()
                    ->rowCount();
        } catch (PDOException $ex) {
            $this->p('<b>DB ERROR updating giftcodes:</b> '.$ex->getMessage(),'p',true);
        }
        return $rowsUpdated;
    }
    public function updatePlayerMessageGiftcodeID($playerId,$giftCodeID,$msg) {
        $params = [
            'last_message'  => $msg,
            'updated_at'    => $this->getTimestring(true,false)
        ];
        if ( !empty($giftCodeID) ) {
            $player = db()
                ->select('players')
                ->find($playerId);
            $extra = new PlayerExtra($player['extra']);
            if ( $extra->addGiftcodeID($giftCodeID) ) {
                $params['giftcode_ids'] = $extra->getGiftcodeIDs();
            }
        }
        db()->update('players')
            ->params($params)
            ->where(['id' => $playerId])
            ->execute();
    }
    public function getGiftcodeID($giftCode) {
        static $giftCodes = [];
        $gcIndex = sprintf('%s-%s',$this->ourAlliance,$giftCode);
        if ( !empty($giftCodes[$gcIndex]) ) {
            return $giftCodes[$gcIndex];
        }
        $giftCodes[$gcIndex] = db()
            ->select('giftcodes','id')
            ->where(['code' => $giftCode])
            ->first();
        return $giftCodes[$gcIndex];
    }
    public function getPlayersForGiftcode($giftCode,$countOnly=true) {
        $giftCodeID = $this->getGiftcodeID($giftCode);
        $playerQuery = db()
            ->select('players')
            ->where('last_message', 'not like', $giftCode.': %') // Could remove this at some point
            ->where('giftcode_ids', 'not like', "%|$giftCodeID|%")
            ->where('extra', 'not like', '%ignore":1%');
        return ( $countOnly ? $playerQuery->count() :
                        $playerQuery->orderBy('id','asc')->all() );
    }

    public function deleteById($table,$id) {
        try {
            $result = db()
                ->delete($table)
                ->where(['id' => $id])
                ->execute();
            return $result->rowCount();
        } catch (PDOException $ex) {
            $this->p('<b>DB ERROR Deleting:</b> '.$ex->getMessage(),'p',true);
        } catch (\Exception $ex) {
            $this->p('<b>Exception Deleting:</b> '.$ex->getMessage(),'p',true);
        }
        return -1;
    }

    ///////////////////////////////////////////////////////////////////////
    // Print / logging functions
    public function p($msg,$htmlType=null,$log=false) {
        // In webmode, do print to stdout. Otherwise only log file.
        if ( $this->webMode ) {
            if ( $htmlType=='RAW' ) {
                print $msg."\n";
            } else {
                $format = ( empty($htmlType) ? "%s\n" : "<$htmlType>%s</$htmlType>\n" );
                response()->markup( sprintf($format,$msg) );
            }
        }
        if ($log || !$this->webMode) {
            $this->logInfo($msg);
        }
    }
    public function pDebug($msg,$text) {
        $this->p("$msg: ".print_r($text,true),'pre',true);
    }

    public function logInfo($msg) {
        $this->log->info( $this->myPID.') '.str_replace("\t"," ",trim(strip_tags($msg))) );
    }


    ///////////////////////// Guzzle functions
    public function signInWOS($fid) {
/*
    ====== Headers:
    Date: Sun, 30 Jun 2024 16:42:27 GMT
    Content-Type: application/json
    Transfer-Encoding: chunked
    Connection: keep-alive
    Server: nginx/1.16.1
    X-Powered-By: PHP/7.4.19
    Cache-Control: no-cache, private
    X-RateLimit-Limit: 30
    X-RateLimit-Remaining: 29
    Access-Control-Allow-Origin: *
    ======== Body:
    {"code":1,"data":[],"msg":"params error","err_code":""}
    {"code":1,"data":[],"msg":"Sign Error","err_code":0}
    {"code":0,"data":{
        "fid":33750731,
        "nickname":"lord33750731",
        "kid":245,
        "stove_lv":10,
        "stove_lv_content":10,
        "avatar_image":"https:\/\/gof-formal-avatar.akamaized.net\/avatar-dev\/2023\/07\/17\/1001.png"
        },
        "msg":"success","err_code":""}
*/
        return $this->guzzlePOST(
            'https://wos-giftcode-api.centurygame.com/api/player',
            $fid
        );
    }

    public function sendGiftCodeWOS($fid, $giftCode) {
/*
Headers:
    [date] => Tue, 02 Jul 2024 13:03:07 GMT
    [content-type] => application/json
    [transfer-encoding] => chunked
    [connection] => keep-alive
    [server] => nginx/1.16.1
    [x-powered-by] => PHP/7.4.19
    [cache-control] => no-cache, private
    [x-ratelimit-limit] => 30
    [x-ratelimit-remaining] => 28
    [access-control-allow-origin] => *
Body1:
    [code] => 0
    [data] => Array()
    [msg] => SUCCESS
    [err_code] => 20000
Body2:
    [code] => 1
    [data] => Array()
    [msg] => RECEIVED.
    [err_code] => 40008
Body3:
    [code] => 1
    [data] => Array()
    [msg] => CDK NOT FOUND.
    [err_code] => 40014
*/
        return $this->guzzlePOST(
            'https://wos-giftcode-api.centurygame.com/api/gift_code',
            $fid,
            $giftCode
        );
    }

    private function guzzlePOST($url,$fid,$cdk='') {
        static $guzClient = new Client(['timeout'=>10]); // Guzzle outbound HTTP client

        // These statics are for debug use
        static $rateRemainId   = 0;
        static $rateRemainCode = 0;

        ///////////////// DEBUG - EMULATE
        if ( $this->guzEmulate ) {
            if ($rateRemainId<1)   { $rateRemainId     =7; }
            if ($rateRemainCode<1) { $rateRemainCode   =7; }
            if ( ! empty($cdk) ) {
                // Redeem gift code
                $rateRemainCode--;
                return [
                    'code'          => 0,
                    'data'          => [],
                    'msg'           => ($rateRemainCode < 1 ? 'fail429' : 'SUCCESS'),
                    'err_code'      => 20000,
                    'headers'       => [
                        'x-ratelimit-limit' => 30,
                        'x-ratelimit-remaining' => $rateRemainCode
                    ],
                    'http-status'   => ($rateRemainCode < 1 ? 429 : 200),
                    'guzExceptionMessage' => 'guz is happy'
                ];
            } else {
                // Player Log in
                $rateRemainId--;
                $stove = rand(8,29);
                $f = '{"fid":%d,"nickname":"lord%d","kid":245,"stove_lv":%d,"stove_lv_content":%d,'.
                    '"avatar_image":"https:\/\/gof-formal-avatar.akamaized.net\/avatar-dev\/2023\/07\/17\/1001.png"}';
                return [
                    'code'          => 0,
                    'data'          => json_decode(sprintf($f,$fid,$fid,$stove,$stove)),
                    'msg'           => ($rateRemainCode < 1 ? 'fail429' : 'success'),
                    'err_code'      => '',
                    'headers'       => [
                        'x-ratelimit-limit'     => 30,
                        'x-ratelimit-remaining' => $rateRemainId
                    ],
                    'http-status'   => ($rateRemainId < 1 ? 429 : 200),
                    'guzExceptionMessage' => 'guz is happy'
                ];
            }
        }

        //////////////// PRODUCTION => hit WOS API
        $timestring = $this->getTimestring(empty($cdk));
        $signRaw = ($cdk ? "cdk=$cdk&" : '').
            "fid=$fid&time=$timestring".self::HASH;
        $formParams = [
            'sign' => md5($signRaw),
            'fid'  => $fid,
            'time' => $timestring
        ];
        if ($cdk) {
            $formParams['cdk'] = $cdk;
        }
        try {
            $guzExceptionMessage = '';
            $guzExceptionCode = 0;
            $response = $guzClient->request('POST',
                $url,
                [
                    'form_params' => $formParams,
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded'
                    ]
                ]
            );
        } catch (\GuzzleHttp\Exception\BadResponseException $e ) {
            // With a 4xx or 5xx HTTP return code, Guzzle throws this exception.
            // Pull out Response object from exception class, process as "normal"
            $response = $e->getResponse();
            $guzExceptionCode = $e->getCode();
            $guzExceptionMessage = "$guzExceptionCode: ".$e->getMessage();
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // Networking error
            $guzExceptionCode = $e->getCode();
            $guzExceptionMessage = "$guzExceptionCode: ".$e->getMessage();
            $response = null;
        } catch (\Exception $e) {
            // Catch curl errors, like '28: Operation timed out'
            $guzExceptionCode = $e->getCode();
            $guzExceptionMessage = "$guzExceptionCode: ".$e->getMessage();
            $response = null;
        }

        $headers = [];
        if ( !empty($response) ) {
            $body = json_decode($response->getBody());
            // Headers: Force all param names to lower case and
            // combine values array into a string
            foreach ($response->getHeaders() as $name => $values) {
                $headers[strtolower($name)] = implode(',',$values);
            }
            if ( $this->dbg ) {
                $this->p("<br/>======== HTTP return code: ".$response->getStatusCode(),'p',true);
                $this->pDebug('Headers: ',$headers);
                $this->pDebug('Body: ',$body);
            }
        }
        return [
            'code'          => (isset($body->code) ? $body->code : null),
            'data'          => (isset($body->data) ? $body->data : null),
            'msg'           => (isset($body->msg) ? $body->msg : null),
            'err_code'      => (isset($body->err_code) ? $body->err_code : null),
            'headers'       => $headers,
            'http-status'   => (!empty($response) ? $response->getStatusCode() : null),
            'guzExceptionMessage' => $guzExceptionMessage,
            'guzExceptionCode' => $guzExceptionCode
        ];
    }

}