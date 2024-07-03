<?php

namespace App\Controllers;

use GuzzleHttp\Client;
use Leaf\Controller;
use Leaf\Date;
use Leaf\Http\Request;
use PDOException;

class WosController extends Controller {
    const HASH = "tB87#kPtkxqOS2";      // WOS API secret
    const OUR_STATE = 245;              // State restriction
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
        $this->htmlHeader();
        $this->p('<ul>');
        $this->p('Database players: <a href="/players">/players</a>','li');
        $this->p('Send a reward: <a href="/send/">/send/</a>[giftcode]','li');
        $this->p('Add a player: <a href="/add/">/add/</a>[playerID]','li');
        $this->p('Remove a player: <a href="/remove/">/remove/</a>[playerID]','li');
        $this->p('</ul>');
        $this->htmlFooter();
    }

    /**
     * List players & last gift reward result.
     */
    public function players() {
        $this->htmlHeader('== Player list');
        try {
            $all_players = db()
                ->select('players')
                ->orderBy('id','asc')
                ->all();
                $this->p('<table>'.
                '<tr><th>id</th><th>Name</th><th>F#</th>'.
                '<th>Last message</th><th>Last Update UTC</th></tr>');
            foreach ($all_players as $p) {
                $this->p('<tr>');
                $this->p($p['id'],'td');
                $this->p('<img src="'.$p['avatar_image'].'" width="20"> <b>'.$p['player_name'].'</b>','td');
                $this->p((strlen($p['stove_lv_content']) > 6 ?
                        '<img src="'.$p['stove_lv_content'].'" width="30">' :
                        'f'.$p['stove_lv']
                    )
                    ,'td');
                $this->p($p['last_message'],'td');
                $this->p($p['updated_at'],'td');
                $this->p('</tr>');
            }
            $this->p('</table>');
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
        $this->p("Sending <b>$giftCode</b> to all players:",'p');
        try {
            $all_players = db()
                ->select('players')
                ->where('last_message', 'not like', $giftCode)
                ->orderBy('id','asc')
                ->all();
            if (count($all_players)==0) {
                $this->p('No players in the database that still need that gift code.','p');
            }
            foreach ($all_players as $p) {
                response()->sendContent();
                // Verify player
                $signInResponse = $this->signIn($p['id']);
                $s = $signInResponse['data'];
                $state = empty($s->kid) ? 0 : $s->kid;
                $this->p('<p>'.$p['id'].' <b>#'.$state.' '.$p['player_name'].'</b>: ');
                if (empty($state) || $state!=self::OUR_STATE) {
                    $this->p('Deleting, invalid user or state</p>');
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
                // ?? Perhaps use API ratelimit feedback here?
                if ($signInResponse['headers']['x-ratelimit-remaining'] < 3) {
                    sleep (10);
                }

                // Send gift code
                $tries = 2;
                while ($tries>0) {
                    $giftResponse = $this->sendGiftCode($p['id'],$giftCode);
                    $giftErrCode = $giftResponse['err_code'];
                    if ($giftErrCode == 40014) {
                        // Invalid gift code
                        $this->p('Aborting: Invalid gift code','b');
                        break 2;
                    }
                    $tries--;
                    if ($giftResponse['http-status']==429) {
                        // Too many requests
                        $ratelimitReset = $giftResponse['headers']['x-ratelimit-reset'];
//                        $resetAt = tick($ratelimitReset);
                        $resetIn = tick(intval($ratelimitReset) - time());
                        if (_env('APP_DEBUG')=='true') {
                            $this->p("429: x-ratelimit-reset=$ratelimitReset now=".time().
                                " resetIn=$resetIn"
//                            "resetAt=".$resetAt->format('U'));
                                ,'p');
                        }
                        db()->update('players')
                            ->params([
                                'last_message'  =>"Too many attempts: Retry in $resetIn seconds",
                                'updated_at'    => $this->getTimestring(false,false)
                            ])
                            ->where(['id' => $p['id']])
                            ->execute();
                        sleep(2); // ?? change to $resetIn
                    } else { // Success!
                        break;
                    }
                }
                $msg = ( $giftErrCode==20000 ? 'processed succesfully!' :
                        ($giftErrCode==40008 ? 'gift code already used' :
                                               "$giftErrCode ".$giftResponse['msg']) );
                $this->p("$msg</p>\n");
                db()->update('players')
                    ->params([
                        'last_message'  => "$giftCode: $msg",
                        'updated_at'    => $this->getTimestring(false,false)
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
                    }
                    $this->p('Name <b>'.$data->nickname.'</b> inserted into database','p');
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

    ///////////////////////// Helper functions
    private function validateId($player_id) {
        if (!empty($player_id)) {
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
        $timestring = $this->getTimestring();
        if (_env('APP_DEBUG')=='true') {
            $this->p("Form params:<br/>\n".
                    "sign raw: fid=$fid&time=$timestring".self::HASH."\n".
                    "sign md5: ".md5("fid=$fid&time=$timestring".self::HASH),'pre');
        }
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

        $response = $this->guz->request('POST',
            'https://wos-giftcode-api.centurygame.com/api/player',
            [
                'form_params' =>
                [
                    'sign' => md5("fid=$fid&time=$timestring".self::HASH),
                    'fid'  => $fid,
                    'time' => $timestring
                ],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]
        );
        $body = json_decode($response->getBody());
        if (_env('APP_DEBUG')=='true') {
            $this->pDebug('Headers: ',$this->parseGuzzleHeaders($response));
            $this->pDebug('Body: ',$body);
        }
        return [
            'code'          => $body->code,
            'data'          => $body->data,
            'msg'           => $body->msg,
            'err_code'      => $body->err_code,
            'headers'       => $this->parseGuzzleHeaders($response),
            'http-status'   => $response->getStatusCode()
        ];
    }

    private function sendGiftCode($fid, $giftCode) {
        $timestring = $this->getTimestring(true);
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
        $response = $this->guz->request('POST',
            'https://wos-giftcode-api.centurygame.com/api/gift_code',
            [
                'form_params' =>
                [
                    'sign' => md5("cdk=$giftCode&fid=$fid&time=$timestring".self::HASH),
                    'fid'  => $fid,
                    'time' => $timestring,
                    'cdk'  => $giftCode
                ],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]
        );
        $body = json_decode($response->getBody());
        if (_env('APP_DEBUG')=='true') {
            $this->pDebug('Headers: ',$this->parseGuzzleHeaders($response));
            $this->pDebug('Body: ',$body);
        }
        return [
            'code'          => $body->code,
            'data'          => $body->data,
            'msg'           => $body->msg,
            'err_code'      => $body->err_code,
            'headers'       => $this->parseGuzzleHeaders($response),
            'http-status'   => $response->getStatusCode()
        ];
    }

    private function getTimestring($renew=false,$inUnixTime=true) {
        if ($renew) {
            $this->time = tick();
        }
	    return (string) $this->time->format($inUnixTime ? 'U':
                'YYYY-MM-DD HH:mm:ss');
    }

    private function parseGuzzleHeaders($response) {
        // Force all param names to lower case and
        // combine values array into a string
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(',',$values);
        }
        return $headers;
    }

    ///////////////////////// View functions
    private function htmlHeader($title=null) {
        $this->p('<html><head><style>');
        $this->p('th, td, tr { padding: 2px; text-align: left; }'); // border: 1px solid grey
        $this->p('th { text-decoration: underline; }');
        #$this->p('th { border-bottom: 1px solid black; }');
        $this->p('</style></head>');
        $this->p('<body><h1>WOS #245 Gift Rewards</h1>');
        $this->p('<a href="/">Home</a>','p');
        if ($title) {
            $this->p($title,'h3');
        }
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

