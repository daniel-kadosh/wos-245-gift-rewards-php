<?php

namespace App\Controllers;

use Leaf\Controller;
use Leaf\Http\Request;

class WosController extends Controller {
    public function __construct() {
        parent::__construct();
        $this->request = new Request;
        db()->autoConnect();
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
        $all_players = db()->select("players")->all();
        response()->markup("<table><tr><th>player_id</th><th>name</th><th>last message</th></tr>\n");
        foreach ($all_players as $p) {
            response()->markup("<tr><td>".$p['player_id'].
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
        response()->markup("Add player id=$player_id<br/>");
        $result = db()
            ->insert("players")
            ->params([
                "player_id" => $player_id,
                "player_name" => "divergent",
                "last_message" => "(new player)"
                ])
            ->execute();
        $this->htmlFooter();
    }

    /**
     * Remove player.
     */
    public function remove($player_id) {
        $player_id = $this->validateId($player_id);
        $this->htmlHeader();
        response()->markup("REMOVE player id=$player_id<br/>");
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