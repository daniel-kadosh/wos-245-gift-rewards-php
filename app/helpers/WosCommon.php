<?php

namespace App\Helpers;

use Exception;
use GuzzleHttp\Client;
use Leaf\Config;
use Leaf\Log;

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

#    private $con;
#    public function __construct($controllerObject) {
#        $this->con = $controllerObject;
    public function __construct() {

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
            $baseLogfile = ( $this->webMode ? 'wos_controller_' : 'wos_daemon_' );
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
        static $myPid = getmypid();
        static $prefix = ($this->webMode ? 'W' : 'd' ); // Web or daemon
        $this->log->info( "$prefix$myPid) ".str_replace("\n"," ",trim(strip_tags($msg))) );
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
        if ( $this->dbg ) {
            $this->p("Form params:<br/>\n".
                    "sign raw: $signRaw\n".
                    "sign md5: ".md5($signRaw),'pre');
        }
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