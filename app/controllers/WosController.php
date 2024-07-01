<?php

namespace App\Controllers;

use GuzzleHttp\Client;
use Leaf\Controller;
use Leaf\Date;
use Leaf\Http\Request;
use PDOException;

class WosController extends Controller {
    const HASH = "tB87#kPtkxqOS2";      // WOS API secret
    private $time = null;               // tick() DateTime object
    private $guz;                       // Guzzle HTTP client object
    private $rateLimitRemaining = -1;   // API rate limit in sec

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
        $this->p("Database players: <a href=\"/players\">/players</a>",'li');
        $this->p("Send a reward: <a href=\"/send/\">/send/</a>[giftcode]",'li');
        $this->p("Add a player: <a href=\"/add/\">/add</a>[playerID]",'li');
        $this->p("Remove a player: <a href=\"/remove/\">/remove</a>[playerID]",'li');
        $this->p('</ul>');
        $this->htmlFooter();
    }

    /**
     * List players & last gift reward result.
     */
    public function players() {
        $this->htmlHeader();
        $this->p('Player list:','p');
        $all_players = db()
            ->select('players')
            ->orderBy('id')
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
                    'F'.$p['stove_lv']
                )
                ,'td');
            $this->p($p['last_message'],'td');
            $this->p($p['updated_at'],'td');
            $this->p('</tr>');
        }
        $this->p('</table>');
        $this->htmlFooter();
    }

    /**
     * Create a new player.
     */
    public function add($player_id) {
        $player_id = $this->validateId($player_id);
        $this->htmlHeader();
        $this->p("Adding player id=$player_id",'p');
        try {
            // Check for duplicate before hitting WOS API
            $result = db()
                ->select('players')
                ->find($player_id);
            if (_env('APP_DEBUG')=='true') {
                $this->pDebug('SELECT by player_id',$result);
            }
            if (!empty($result)) {
                $this->p('<b>ERROR:</b> player ID already exists, ignored.','p');
            } else {
                // Verify player is in #245 thru WOS API
                $response = $this->signIn($player_id);
                $data = $response['data'];
                if ($data->kid == 245) {
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
        $this->htmlHeader();
        $this->p("REMOVING player id=$player_id",'p');
        try {
            $result = db()
                ->delete('players')
                ->where(['id' => $player_id])
                ->execute();
            $count = $result->rowCount();
            if ($count > 0) {
                $this->p('Success!','p');
            } else {
                response()->exit('Player ID not found',404);
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
    public function send() {
        //
    }

    ///////////////////////// Helper functions
    private function validateId($player_id) {
        if (!is_null($player_id)) {
            $int_id = abs(intval($player_id));
            if ($int_id > 0 && $int_id <= PHP_INT_MAX ) {
                return $int_id;
            }
        }
        $this->htmlHeader();
        response()->markup('ERROR: invalid player id='.$player_id.'<br/>');
        $this->htmlFooter();
        response()->exit('Invalid ID '.$player_id,400);
    }

    private function signIn($fid) {
        $timestring = $this->getTimestring();
        response()->markup("Form params:<br/>\n<pre>".
                "sign raw: fid=$fid&time=$timestring".self::HASH."\n".
                "sign md5: ".md5("fid=$fid&time=$timestring".self::HASH)."\n</pre>\n");
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
        $rateLimitRemaining = intval($response->getHeader('X-RateLimit-Remaining'));

        $this->p('========Body:<br/>'.$response->getBody(),'pre');
        $body = json_decode($response->getBody());
        $this->pDebug('Body: ',$body);
        $data = $body->data;
        return [
            'data' => $data,
            'x-ratelimit-remaining' => $rateLimitRemaining
        ];
    }

    private function getTimestring() {
        // String of UNIX time
	    return (string) $this->time->format('U');
    }

    ///////////////////////// View functions
    private function htmlHeader() {
        $this->p('<html><head><style>');
        $this->p('th, td, tr { padding: 2px; }');
        $this->p('</style></head>');
        $this->p('<body><h1>WOS #245 Gift Rewards</h1>');
        $this->p('<a href="/">Home</a>','p');
    }
    private function htmlFooter() {
        $this->p('</body></html>');
    }
    private function p($msg,$htmlType=null) {
        response()->markup((empty($htmlType) ? '' : "<$htmlType>").
            $msg. (empty($htmlType) ? '' : "</$htmlType>"). "\n");
    }
    private function pDebug($msg,&$text) {
        $this->p("$msg: ".print_r($text,true)."\n",'pre');
    }
}

