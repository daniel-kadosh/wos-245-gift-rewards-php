<?php

namespace App\Controllers;

use GuzzleHttp\Client;
use Leaf\Controller;
use Leaf\Http\Request;
use PDOException;

class WosController extends Controller {
    const HASH          = "tB87#kPtkxqOS2"; // WOS API secret
    const OUR_STATE     = 245;              // State restriction
    const LIST_COLUMNS  = [                 // Column labels to DB field names
            'ID'                => 'id',
            'Name'              => 'player_name',
            'F#'                => 'stove_lv',
            'Last Message'      => 'last_message',
            'Last Update UTC'   => 'updated_at'
        ];

    private $time = null;               // tick() DateTime object
    private $guz;                       // Guzzle HTTP client object

    public function __construct() {
        parent::__construct();
        $this->request = new Request;
        db()->autoConnect();
        $this->time = tick();
        $this->guz = new Client(['timeout'=>10]);
    }

    /**
     * Default menu.
     */
    public function index() {
        $this->htmlHeader('== Application capabilities:');
        $this->p('<table style="margin-left:30px;">');
        $lineFormat = '<td><li><a href="/%s">/%s</a>%s</li></td>'.
                '<td><b>%s:</b> %s</td>';
        $this->p(sprintf($lineFormat,'players','players','',
            'Player list','Can sort and download list, plus one-click remove a player'),'tr');
        $this->p(sprintf($lineFormat,'send/','send/','[giftcode]',
            'Send a reward','to send ALL players the giftcode.'.
            '<br/><b>NOTE:</b> page will take 2-5 minutes to show anything, let it run and wait!'),'tr');
        $this->p(sprintf($lineFormat,'add/','add/','[playerID]',
            'Add a player','Will get basic player info and check they are in state #'.self::OUR_STATE),'tr');
        $this->p(sprintf($lineFormat,'remove/','remove/','[playerID]',
            'Remove a player','If you change your mind after removing, just add again <b>;-)</b>'),'tr');
        $this->p(sprintf($lineFormat,'download','download/','[format]',
            'Download player DB','Supported formats: <b>csv</b>, <b>json</b>, <b>curl</b> (bash script to re-add users)'),'tr');
        $this->p('<td colspan="2">&nbsp;</td>','tr'); // empty row
        $this->p('<tr><td colspan="2">');
        $this->p('<a href="https://github.com/daniel-kadosh/wos-245-gift-rewards-php" target="_blank">'.
                'Github</a>'
            );
        $this->p(file_get_contents('git-info'),'pre');
        $this->p('</td></tr></table>');
        $this->htmlFooter();
    }

    /**
     * List players & last gift reward result.
     */
    public function players() {
        $this->htmlHeader('== Player list');
        $sort = strtolower(request()->params('sort','player_name' ));
        $dir  = strtolower(request()->params('dir' ,'asc'));
        if ( array_search($sort,self::LIST_COLUMNS,true) === false ) {
            $this->p(" (Ignored invalid sort column $sort)");
            $sort = 'id';
        }
        if ( array_search($dir,['asc','desc'],true) === false) {
            $this->p(" (Ignored invalid sort direction $dir)");
            $dir = 'asc';
        }
        $this->p('<table><tr><th width="20">#</th>');
        $colFormat = '<a href="/players?sort=%s&dir=%s">%s</a>';
        foreach (array_keys(self::LIST_COLUMNS) as $colName) {
            $newDir = 'asc';
            if ( $sort == self::LIST_COLUMNS[$colName] ) {
                $newDir = ($dir=='asc' ? 'desc' : 'asc');
            }
            $this->p(sprintf($colFormat,
                self::LIST_COLUMNS[$colName],
                $newDir,
                $colName),'th');
        }
        $this->p('<th>Actions</th></tr>');
        $actionFormat = '<input onclick="return removeConfirm(\'%s\')" '.
                        'type="submit" value="%s" formmethod="get"/>';
        try {
            if ($sort=='player_name') {
                $sort = $sort.' COLLATE NOCASE';
            }
            $allPlayers = db()
                ->select('players')
                ->orderBy($sort,$dir)
                ->all();
            $n = 1;
            foreach ($allPlayers as $p) {
                $this->p('<tr>');
                $this->p($n++.']','td');
                foreach ( self::LIST_COLUMNS as $col ) {
                    switch ($col) {
                        case 'player_name' :
                            $this->p('<img src="'.$p['avatar_image'].'" width="20"> <b>'.
                                    $p['player_name'].'</b>','td');
                            break;
                        case 'stove_lv' :
                            $this->p((strlen($p['stove_lv_content']) > 6 ?
                                        '<img src="'.$p['stove_lv_content'].'" width="30">' :
                                        'f'.$p['stove_lv']
                                    ), 'td');
                            break;
                        case 'id' :
                        case 'last_message' :
                        case 'updated_at':
                            $this->p($p[$col],'td');
                            break;
                        default:
                            $this->p("Unknown column $col",'td');
                            break;
                    }
                }
                $this->p(sprintf($actionFormat,'/remove/'.$p['id'],'Remove'),'th');
                $this->p('</tr>');
            }
            $this->p('</table>');
            if ( count($allPlayers)==0 ) {
                $this->p('No players in the database!','p');
            }
        } catch (PDOException $ex) {
            $this->p('<b>DB ERROR:</b> '.$ex->getMessage(),'p');
        } catch (\Exception $ex) {
            $this->p('<b>Exception:</b> '.$ex->getMessage(),'p');
        }
        $this->htmlFooter();
    }

    /**
     * Send reward code to all users.
     */
    public function send($giftCode) {
        $this->validateGiftCode($giftCode);
        $this->htmlHeader('== Send Gift Code');
        $this->p("Sending <b>$giftCode</b> to all players that haven't received it:",'p');
        try {
            $allPlayers = db()
                ->select('players')
                ->where('last_message', 'not like', $giftCode.'%')
                ->orderBy('id','asc')
                ->all();
            if (count($allPlayers)==0) {
                $this->p('No players in the database that still need that gift code.','p');
            }
            foreach ($allPlayers as $p) {
                // Verify player
                $this->p('<p>'.$p['id'].' - <b>'.$p['player_name'].'</b>: ');
                $tries = 2;
                while ($tries>0) {
                    $signInResponse = $this->signIn($p['id']);
                    $tries--;
                    if ($signInResponse['http-status']==429) {
                        // Hit rate limit!
                        $this->p('(Pausing 61sec due to 429 signIn rate limit) ');
                        sleep(61);
                    } else if ($signInResponse['http-status'] >= 400) {
                        $this->p('<b>WOS signIn API ERROR:</b> '.$signInResponse['guzExceptionMessage'],'p');
                        break 2;
                    } else {
                        // All good!
                        break;
                    }
                }
                $s = $signInResponse['data'];
                $state = empty($s->kid) ? 0 : $s->kid;
                if ($state!=self::OUR_STATE || $signInResponse['err_code'] == 40004) {
                    $this->p('DELETING player: invalid user or state</p>');
                    $this->deletePlayer($p['id']);
                    continue;
                }

                // Update player if needed
                if ( $p['player_name']      != $s->nickname ||
                    $p['avatar_image']      != $s->avatar_image ||
                    $p['stove_lv']          != $s->stove_lv ||
                    $p['stove_lv_content']  != $s->stove_lv_content )
                {
                    db()->update('players')
                        ->params([
                            'player_name'       => $s->nickname,
                            'avatar_image'      => $s->avatar_image,
                            'stove_lv'          => $s->stove_lv,
                            'stove_lv_content'  => $s->stove_lv_content,
                            'updated_at'        => $this->getTimestring(false,false)
                        ])
                        ->where(['id' => $p['id']])
                        ->execute();
                }
                // ?? Use API ratelimit feedback here?
                if ($signInResponse['headers']['x-ratelimit-remaining'] < 2) {
                    $this->p('(pausing 10sec: low x-ratelimit-remaining) ');
                    sleep (10);
                }

                // Send gift code
                $tries = 3;
                while ($tries>0) {
                    $giftResponse = $this->sendGiftCode($p['id'],$giftCode);
                    $tries--;
                    $giftErrCode = $giftResponse['err_code'];
                    if ($giftErrCode == 40014) {
                        // Invalid gift code
                        $this->p('Aborting: Invalid gift code','b');
                        break 2;
                    }
                    $resetIn = 0;
                    if ($giftErrCode == 40004) {
                        $resetIn = 20;
                        $msg = "Gift errCode=$giftErrCode";
                    } else if ($giftResponse['http-status']==429) {
                        // Too many requests
                        if ( !empty($giftResponse['headers']['x-ratelimit-reset']) ) {
                            $ratelimitReset = $giftResponse['headers']['x-ratelimit-reset'];
                            // Convert from UNIX time?
                            $resetAt = (intval($ratelimitReset) == $ratelimitReset ?
                                            tick("@$ratelimitReset") : tick());
                            $resetIn = intval($ratelimitReset) - intval($this->getTimestring(false,true));
                        } else {
                            $resetAt = tick();
                            $ratelimitReset = -1;
                        }
                        // For sanity, until I see real values for x-ratelimit-reset
                        if ( $resetIn < 1 || $resetIn > 65) {
                            $resetIn = 21;
                        }
                        //if (_env('APP_DEBUG')=='true') {
                        // Force debug info for this case, as we haven't seen this live.
                        // The 60sec sleep for a 429 in signIn above seems to have solved
                        // this whole issue, and we may not need to sleep here at all.
                            $this->pDebug('Headers: ',$giftResponse['headers']);
                            $this->p("<br/>429: x-ratelimit-reset=$ratelimitReset"
                                ." now=".$this->getTimestring(false,true)
                                ."=".$this->getTimestring(false,false)."<br/>\n"
                                ." resetIn=$resetIn"
                                ." resetAt=".$resetAt->format('YYYY-MM-DD HH:mm:ss')
                                ,'p');
                        //}
                        $msg = "http 429 Too many attempts";
                    }
                    if ( $resetIn > 0 ) {
                        $msg = "$msg: ".$giftResponse['msg']." - pausing $resetIn sec.";
                        $this->p("($msg)");
                        db()->update('players')
                            ->params([
                                'last_message'  => $msg,
                                'updated_at'    => $this->getTimestring(false,false)
                            ])
                            ->where(['id' => $p['id']])
                            ->execute();
                        sleep($resetIn);
                    } else if ($giftResponse['http-status'] >= 400) {
                        $this->p('<b>WOS gift API ERROR:</b> '.$giftResponse['guzExceptionMessage'],'p');
                    } else { // Success!
                        break;
                    }
                }
                switch ($giftErrCode) {
                    case 20000:
                        $msg = "$giftCode: redeemed succesfully";
                        break;
                    case 40008:
                        $msg = "$giftCode: gift code already used";
                        break;
                    default:
                        $msg = "$giftErrCode ".$giftResponse['msg'];
                        break;
                }
                $this->p("$msg</p>\n");
                db()->update('players')
                    ->params([
                        'last_message'  => $msg,
                        'updated_at'    => $this->getTimestring(true,false)
                    ])
                    ->where(['id' => $p['id']])
                    ->execute();
            }
        } catch (PDOException $ex) {
            $this->p('<b>DB ERROR:</b> '.$ex->getMessage(),'p');
        } catch (\Exception $ex) {
            $this->p('<b>Exception:</b> '.$ex->getMessage(),'p');
        }
        $this->htmlFooter();
    }

    /**
     * Create a new player.
     */
    public function add($player_id) {
        $player_id = $this->validateId($player_id);
        $this->htmlHeader('== Add player');
        $this->p("Adding player id=$player_id",'p');
        try {
            // Check for duplicate before hitting WOS API
            $result = db()
                ->select('players')
                ->find($player_id);
            if (_env('APP_DEBUG')=='true') {
                $this->pDebug('SELECT by player_id ',$result);
            }
            if (!empty($result)) {
                $this->p('<b>ERROR:</b> player ID already exists, ignored.','p');
            } else {
                // Verify player is in #245 thru WOS API
                $response = $this->signIn($player_id);
                if ($response['err_code'] == 40004) {
                    $this->p('<b>ERROR:</b> player ID does not exist in WOS, ignored.','p');
                } else if ($response['http-status'] >= 400) {
                    $this->p('<b>WOS API ERROR:</b> '.$response['guzExceptionMessage'],'p');
                } else {
                    $data = $response['data'];
                    if ($data->kid == self::OUR_STATE) {
                        $result = db()
                            ->insert('players')
                            ->params([
                                'id'            => $player_id,
                                'player_name'   => $data->nickname,
                                'last_message'  => '(Created)',
                                'avatar_image'  => $data->avatar_image,
                                'stove_lv'      => $data->stove_lv,
                                'stove_lv_content' => $data->stove_lv_content,
                                'created_at'    => $this->time->format('YYYY-MM-DD HH:mm:ss'),
                                'updated_at'    => $this->time->format('YYYY-MM-DD HH:mm:ss')
                                ])
                            ->execute();
                        if (_env('APP_DEBUG')=='true') {
                            $this->pDebug('INSERT ',$result);
                        }
                        $this->p('Name <b>'.$data->nickname.'</b> inserted into database','p');
                    } else {
                        $this->p('IGNORED: <b>'.$data->nickname.
                            '</b> is in invalid state $'.$data->kid,'p');
                    }
                }
            }
        } catch (PDOException $ex) {
            $this->p('<b>DB ERROR:</b> '.$ex->getMessage(),'p');
        } catch (\Exception $ex) {
            $this->p('<b>Exception:</b> '.$ex->getMessage(),'p');
        }
        $this->htmlFooter();
    }

    /**
     * Remove player.
     */
    public function remove($player_id) {
        $player_id = $this->validateId($player_id);
        $this->htmlHeader('== Remove player');
        $count = $this->deletePlayer($player_id);
        if ($count < 1) {
            response()->exit("Player id=$player_id not found",404);
        }
        $this->p("REMOVED player id=$player_id succesfully",'p');
        $this->htmlFooter();
    }

    /**
     * Download CSV or XLS of database
     */
    public function download($fileFormat = '') {
        $formats = [
            'csv'   => ['ct' => 'text/csv',         'ext' => 'csv' ],
            'json'  => ['ct' => 'application/json', 'ext' => 'json'],
            'curl'  => ['ct' => 'text/plain',       'ext' => 'sh'  ]
        ];
        $format = trim(strtolower($fileFormat));

        // Usage if no format in URL
        if (empty($fileFormat) || array_search($fileFormat,array_keys($formats),true)===false) {
            $this->htmlHeader('== Download player database');
            if (!empty($fileFormat)) {
                $this->p('<b>Invalid format:</b> '.$fileFormat,'p');
            }
            $this->p('Formats supported:','b');
            $this->p('<table style="margin-left:30px;">');
            $lineFormat = '<td><li><a href="/download/%s">/download/%s</a></li></td>'.
                    '<td>- %s</td>';
            $this->p(sprintf($lineFormat,'csv','csv',
                'Standard CSV file'),'tr');
            $this->p(sprintf($lineFormat,'json','json',
                'File with each line as 1 row of the database as a JSON string'),'tr');
            $this->p(sprintf($lineFormat,'curl','curl',
                'Bash script with curl calls to add players into the database (DB backup of sorts)'),'tr');
            $this->p('</table>');
            $this->htmlFooter();
            return;
        }

        // Handle download header + content carefully, without any HTML
        response()->withHeader([
                'Content-Type'        => $formats[$fileFormat]['ct'],
                'Content-Disposition' => sprintf(
                        'attachment; filename="wos245players_%s.%s"',
                        substr($this->getTimestring(true,false),0,10),
                        $formats[$fileFormat]['ext']
                    )
            ])->sendHeaders();
        $allPlayers = db()
            ->select('players')
            ->orderBy('id','asc')
            ->all();

        // PHP to handle output buffering
        ob_start();
        switch ($format) {
            case 'json':
                foreach ($allPlayers as $p) {
                    print json_encode($p)."\n";
                }
                break;
            case 'csv':
                $stdout = fopen('php://output', 'w');
                $n = 0;
                foreach ($allPlayers as $p) {
                    if ($n++ == 0) {
                        fputcsv($stdout,array_keys($p));
                    }
                    fputcsv($stdout,$p);
                }
                break;
            case 'curl':
                print "#!/bin/bash\n# Script to add all users\n\n";
                $curlAuth = '--digest -u "wos245valhalla:divergent"';
                $curlUrl = rtrim(_env('APP_URL'),'/');
                $n = 1;
                foreach ($allPlayers as $p) {
                    printf("curl -s %s/add/%d %s | grep -e '^<p>'\n",
                        $curlUrl, $p['id'], $curlAuth);
                    if ( $n++ % 29 == 0) {
                        print "sleep 61\n";
                    }
                }
                break;
            default:
                break;
        }
        ob_flush();
        #ob_end_clean();
        #response()->send();
    }

    ///////////////////////// Helper functions
    private function validateId($player_id) {
        if (!empty($player_id)) {
            $player_id = trim($player_id);
            $int_id = abs(intval($player_id));
            if ($int_id > 0 && $int_id <= PHP_INT_MAX
                && "$int_id" == "$player_id" )
            {
                return $int_id;
            }
        }
        response()->exit('Invalid ID '.$player_id,400);
    }
    private function validateGiftCode($giftCode) {
        $giftCode = trim($giftCode);
        if (!empty($giftCode) && strlen($giftCode)>4) {
            if (!is_integer($giftCode) && !strpbrk($giftCode,' _/\\|}{][^$')) {
                return $giftCode;
            }
        }
        response()->exit('Invalid Gift Code '.$giftCode,400);
    }
    private function deletePlayer($player_id) {
        try {
            $result = db()
                ->delete('players')
                ->where(['id' => $player_id])
                ->execute();
            return $result->rowCount();
        } catch (PDOException $ex) {
            $this->p('<b>DB ERROR Deleting:</b> '.$ex->getMessage(),'p');
        } catch (\Exception $ex) {
            $this->p('<b>Exception Deleting:</b> '.$ex->getMessage(),'p');
        }
        return 0;
    }

    ///////////////////////// Guzzle functions
    private function signIn($fid) {
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
    {"code":0,"data":{"fid":33750731,"nickname":"lord33750731","kid":245,
        "stove_lv":10,"stove_lv_content":10,
        "avatar_image":"https:\/\/gof-formal-avatar.akamaized.net\/avatar-dev\/2023\/07\/17\/1001.png"},
        "msg":"success","err_code":""}
*/
        return $this->guzzlePOST(
            'https://wos-giftcode-api.centurygame.com/api/player',
            $fid
        );
    }

    private function sendGiftCode($fid, $giftCode) {
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
        $timestring = $this->getTimestring(empty($cdk));
        $signRaw = ($cdk ? "cdk=$cdk&" : '').
            "fid=$fid&time=$timestring".self::HASH;
        if (_env('APP_DEBUG')=='true') {
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
            $response = $this->guz->request('POST',
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
            $guzExceptionMessage = $e->getMessage();
        }

        $body = json_decode($response->getBody());
        // Headers: Force all param names to lower case and
        // combine values array into a string
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(',',$values);
        }
        if (_env('APP_DEBUG')=='true') {
            $this->p("<br/>======== HTTP return code: ".$response->getStatusCode(),'p');
            $this->pDebug('Headers: ',$headers);
            $this->pDebug('Body: ',$body);
        }
        return [
            'code'          => (isset($body->code) ? $body->code : null),
            'data'          => (isset($body->data) ? $body->data : null),
            'msg'           => (isset($body->msg) ? $body->msg : null),
            'err_code'      => (isset($body->err_code) ? $body->err_code : null),
            'headers'       => $headers,
            'http-status'   => $response->getStatusCode(),
            'guzExceptionMessage' => $guzExceptionMessage
        ];
    }

    private function getTimestring($renew=false,$inUnixTime=true) {
        if ($renew) {
            $this->time = tick();
        }
	    return (string) $this->time->format($inUnixTime ? 'U':
                'YYYY-MM-DD HH:mm:ss');
    }

    ///////////////////////// View functions
    private function htmlHeader($title=null) {
        $this->p('<html><head><style>');
        $this->p('th, td { padding: 2px; text-align: left; vertical-align: middle; }');
        $this->p('a { font-weight: bold; }');
        $this->p('th { text-decoration: underline; }');
        $this->p('</style>');
        $this->p('<script type="text/javascript">');
        $this->p("
            function removeConfirm(url) {
                if (confirm(`\${url} Are you sure?`)) {
                    location.href = url;
                } else {
                    return false;
                }
            }
            function formConfirm(action,idField) {
                id = document.getElementById(idField).value;
                if (!id) {
                    return false;
                }
                url = `/\${action}/\${id}`;
                removeConfirm(url);
                return false;
            }
            ");
        $this->p('</script>');
        $this->p('<meta name="robots" content="noindex,nofollow" />');
        $this->p("</head>\n<body>");
        $this->p("WOS #245 Gift Rewards",'h1');

        $this->p('<table><tr >');
        $this->p('<a href="/">Home</a>','td');
        $this->p('| <a href="/players">Players</a>','td');
        $this->p('|','td');
        $this->p($this->menuForm('Add'),'td');
        $this->p('|','td');
        $this->p($this->menuForm('Remove'),'td');
        $this->p('|','td');
        $this->p($this->menuForm('Send','Send giftcode'),'td');
        $this->p('</tr></table>');
        if ($title) {
            $this->p($title,'h3');
        }
    }
    private function menuForm($action,$buttonName='') {
        $lAction = strtolower($action);
	if (empty($buttonName)) { $buttonName = $action; }
        $idField = $lAction.'Id';
        return "<form onsubmit=\"return formConfirm('$lAction','$idField');\">".
                "<input type=\"text\" id=\"$idField\" name=\"$idField\" size=\"10\">".
                "<button value=\"$action\">$buttonName</button>".
                '</form>';
    }
    private function htmlFooter() {
        $this->p('</body></html>');
    }
    private function p($msg,$htmlType=null) {
        response()->markup((empty($htmlType) ? '' : "<$htmlType>").
            $msg. (empty($htmlType) ? '' : "</$htmlType>"). "\n");
    }
    private function pDebug($msg,$text) {
        $this->p("$msg: ".print_r($text,true)."\n",'pre');
    }
}

/*
========= log from signIn getting an HTTP 429 inside send():
Form params:

sign raw: fid=36257545&time=1720043353tB87#kPtkxqOS2
sign md5: ea3a1b144633e2af096a706dd1eaeff7
Headers: : Array
(
    [date] => Wed, 03 Jul 2024 21:49:49 GMT
    [content-type] => text/html; charset=UTF-8
    [transfer-encoding] => chunked
    [connection] => keep-alive
    [server] => nginx/1.16.1
    [x-powered-by] => PHP/7.4.19
    [cache-control] => no-cache, private
    [access-control-allow-origin] => *
)

Body: :
(Pausing due to 429 signIn rate limit)
Form params:

sign raw: fid=36257545&time=1720043353tB87#kPtkxqOS2
sign md5: ea3a1b144633e2af096a706dd1eaeff7
Headers: : Array
(
    [date] => Wed, 03 Jul 2024 21:50:51 GMT
    [content-type] => application/json
    [transfer-encoding] => chunked
    [connection] => keep-alive
    [server] => nginx/1.16.1
    [x-powered-by] => PHP/7.4.19
    [cache-control] => no-cache, private
    [x-ratelimit-limit] => 30
    [x-ratelimit-remaining] => 29
    [access-control-allow-origin] => *
)

Body: : stdClass Object
(
    [code] => 0
    [data] => stdClass Object
        (
            [fid] => 36257545
            [nickname] => BabyImposter
            [kid] => 245
            [stove_lv] => 45
            [stove_lv_content] => https://gof-formal-avatar.akamaized.net/img/icon/stove_lv_3.png
            [avatar_image] => https://gof-formal-avatar.akamaized.net/avatar-dev/2023/07/17/1009.png
        )

    [msg] => success
    [err_code] =>
)
*/
