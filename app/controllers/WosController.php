<?php

namespace App\Controllers;

use GuzzleHttp\Client;
use Leaf\Controller;
use Leaf\Date;
use Leaf\Http\Request;
use PDOException;

class WosController extends Controller {
    const HASH = "tB87#kPtkxqOS2";
    private $guz;           // Guzzle client object

    public function __construct() {
        parent::__construct();
        $this->request = new Request;
        db()->autoConnect();
        $this->guz = new Client(['timeout'=>10]);
    }

    /**
     * Default menu.
     */
    public function index() {
        $this->htmlHeader();
        response()->markup("
            <ul><li>Database players: <a href=\"/players\">/players</a></li>
            <li>Send a reward: <a href=\"/send/\">/send/</a>[giftcode]</li>
            <li>Add a player: <a href=\"/add/\">/add</a>[playerID]</li>
            <li>Remove a player: <a href=\"/remove/\">/remove</a>[playerID]</li>
            </ul>");
        $this->htmlFooter();
    }

    /**
     * List players & last gift reward result.
     */
    public function players() {
        $this->htmlHeader();
        response()->markup("Player list:<br\>\n");
        $all_players = db()
            ->select("players")
            ->orderBy('id')
            ->all();
        response()->markup("<table><tr><th>id</th><th>name</th><th>last message</th></tr>\n");
        foreach ($all_players as $p) {
            response()->markup("<tr><td>".$p['id'].
                "</td><td>".$p['player_name'].
                "</td><td>".$p['last_message'].
                "</td></tr>\n");
        }
        response()->markup("</table>\n");
        $this->htmlFooter();
    }

    /**
     * Create a new player.
     */
    public function add($player_id) {
        $player_id = $this->validateId($player_id);
        $this->htmlHeader();

        response()->markup("Adding player id=$player_id<br/>");
        try {
            // Check for duplicate before hitting WOS API
            $result = db()
                ->select("players")->find($player_id);
#                ->where(["id" => $player_id]);

response()->markup("<pre>SELECT result: ".print_r($result,true)."\n</pre>\n");
            if ($result > 0) {
                response()->markup("<b>ERROR:</b> player ID already exists, ignored.");
            } else {
                // Verify player is in #245 thru WOS API
                $response = $this->signIn($player_id);
                $data = $response['data'];
                if ($data['kid'] == 245) {
                    $result = db()
                        ->insert("players")
                        ->params([
                            "id" => $player_id,
                            "player_name" => $data['nickname'],
                            "last_message" => "(Created)"
                            ])
                        ->execute();
                }
                response()->markup("Name <b>".$data['nickname']."</b> inserted into database<br/>\n");
            }
        } catch (PDOException $ex) {
            response()->markup("<p><b>DB ERROR:</b> ".$ex->getMessage()."</p>\n");
        } catch (\Exception $ex) {
            response()->markup("<p><b>Exception:</b> ".$ex->getMessage()."</p>\n");
        }
        $this->htmlFooter();
    }

    /**
     * Remove player.
     */
    public function remove($player_id) {
        $player_id = $this->validateId($player_id);
        $this->htmlHeader();
        response()->markup("REMOVE player id=$player_id<br/>");
        $result = db()
            ->delete("players")
            ->where(["id" => $player_id])
            ->execute();
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
*/        
/*
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
                    "Content-Type" => "application/x-www-form-urlencoded"
                ]
            ]
        );
        $rateLimitRemaining = intval($response->getHeader('X-RateLimit-Remaining'));

        response()->markup("<p>========Body:<br/>".$response->getBody()."</p>\n");
        $body = json_decode($response->getBody());
        $data = $body['data'];
*/
$rateLimitRemaining=5;
$data = ['kid'=>245, 'nickname'=>'D1vergentTST'];
        return [
            'data' => $data,
            'x-ratelimit-remaining' => $rateLimitRemaining
        ];
    }

    private function getTimestring() {
        // https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/toString
        // example: "Tue Aug 19 1975 23:15:30 GMT+0200 (CEST)"
        $time = new Date();
	    return $time->format('ddd MMM D YYYY HH:mm:ss').' GMT+0200 (CEST)';
    }

    ///////////////////////// View functions
    private function htmlHeader() {
        response()->markup("<html><body><h1>WOS #245 Gift Rewards</h1>
            <p><a href=\"/\">Home</a></p>
            <p>");
    }
    private function htmlFooter() {
        response()->markup("</p></body></html>");
    }
}

