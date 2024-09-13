<?php

namespace App\Controllers;

use GuzzleHttp\Client;
use Leaf\Config;
use Leaf\Controller;
use Leaf\Http\Request;
use Leaf\Log;
use PDOException;

class WosController extends Controller {
    const HASH          = "tB87#kPtkxqOS2"; // WOS API secret
    const OUR_STATE     = 245;              // State number restriction
    const OUR_ALLIANCE  = 'VHL';
    const DIGEST_REALM  = 'wos245';         // Apache digest auth realm
    const LIST_COLUMNS  = [                 // Column labels to DB field names
            'ID'                => 'id',
            'Name'              => 'player_name',
            'F#'                => 'stove_lv',
            'Last Message'      => 'last_message',
            'Last Update UTC'   => 'updated_at',
            'Alliance'          => 'x:alliance_name',
            'Comment'           => 'x:comment',
            'Ignore'            => 'x:ignore'
        ];
    const ALLIANCE_COLUMNS = [
            'ID'            => 'id',
            'Short Name'    => 'short_name',
            'Long Name'     => 'long_name',
            'Comment'       => 'comment'
        ];
    const FILTER_NAMES = [
            'alliance_id'   => 'Alliance',
            'ignore'        => 'Ignore'
        ];
    const GIFTCODE_COLUMNS = [
            'ID'                => 'id',
            'First Sent UTC'    => 'created_at',
            'Giftcode'          => 'code',
            'WebUser'           => 'usersSending',
            'Succesful'         => 'succesful',
            'Had Gift'          => 'alreadyReceived',
            'Expected'          => 'expected',
            'Runtime (sec)'     => 'runtime',
            'Last Sent UTC'     => 'updated_at',
            'Deleted Players'   => 'deletedPlayers',
            'hitRateLimit'      => 'hitRateLimit',
            'networkError'      => 'networkError',
            'signinErrorCodes'  => 'signinErrorCodes',
            'giftErrorCodes'    => 'giftErrorCodes',
        ];
    private $time = null;   // tick() DateTime object
    private $guz;           // Guzzle HTTP client object
    private $stats;         // giftCodeStatistics object
    private $log;           // Leaf logger
    private $dbg;           // boolean: true if APP_DEBUG for verbose logging
    private $guzEmulate;    // boolean: true if GUZZLE_EMULATE to not make WOS API calls
    private $badResponsesLeft;  // Number of questionable bad responses from WOS API before abort
    private $dataDir;       // For a number of files used by the app or Apache

    public function __construct() {
        parent::__construct();
        $this->request = new Request;
        db()->autoConnect();
        $this->guz = new Client(['timeout'=>10]);
        $this->dbg = ( _env('APP_DEBUG')=='true' );
        $this->guzEmulate = ( _env('GUZZLE_EMULATE')=='true' );
        $this->dataDir = _env('LOG_DIR', __DIR__.'/../../wos245/');

        // Set up logger
        Config::set('log.style','linux');
        Config::set('log.dir', $this->dataDir);
        Config::set('log.file', 'wos_controller_'.
            substr($this->getTimestring(false,false),0,7).'.log');
        $this->log = app()->logger();
        $this->log->level( $this->dbg ? Log::DEBUG : Log::INFO );
        $this->logInfo( '=== '.$this->request->getUrl().$_SERVER['REQUEST_URI'].'  user='.$_SERVER['REMOTE_USER'] );
    }

    /**
     * Default Home menu.
     */
    public function home() {
        $this->htmlHeader('== Application capabilities:');
        $this->p('<table style="margin-left:30px;">');
        $lineFormat = '<td><li><a href="/%s">/%s</a>%s</li></td>'.
                '<td><b>%s:</b> %s</td>';
        $this->p(sprintf($lineFormat,'alliances','alliances','',
            'Alliance list','Manage alliances list, used to help keep track of players.'),'tr');
        $this->p(sprintf($lineFormat,'players','players','',
            'Player list','Can sort list and easily update &amp; remove players.'),'tr');
        $this->p(sprintf($lineFormat,'send/','send/','[giftcode]',
            'Send a reward','to send ALL players the giftcode if they don\'t have it yet.'.
            '<br/><b>NOTE:</b> page will take 2-5 minutes to show anything, let it run and wait!'.
            '<br/>Player will be verified with WOS and <b>DELETED</b> if not found or not in state #'.
            self::OUR_STATE.'.'),'tr');
        $this->p(sprintf($lineFormat,'add/','add/','[playerID]',
            'Add a player','Will get basic player info from WOS and check they are in state #'.self::OUR_STATE.
            '.<br/>By default will add in alliance ['.self::OUR_ALLIANCE.'] but you can change afterwards.'),'tr');
        $this->p(sprintf($lineFormat,'remove/','remove/','[playerID]',
            'Remove a player','If you change your mind after removing, just add again <b>;-)</b>'),'tr');
        $this->p(sprintf($lineFormat,'updateFromWOS/','updateFromWOS/','[playerID|ignore]',
            'Revalidate with WOS','Updates player metadata (name, furnace, etc) with WOS API.<br/>'.
            'Can update a specific player by ID or those marked "ignore"'),'tr');
        $this->p(sprintf($lineFormat,'giftcodes','giftcodes','',
            'List sent Giftcodes','Summary statistics of Giftcodes sent in the past'),'tr');
        $this->p(sprintf($lineFormat,'download','download/','[format]',
            'Download player DB','Supported formats: <b>csv</b>, <b>json</b>, <b>curl</b> (bash script to re-add users), <b>sqlite3</b>'),'tr');

        $this->p('<td colspan="2">&nbsp;</td>','tr'); // empty row

        $this->p('<td colspan="2">Change your password for website login: '.
                    '<form action="/changepass" method="post">'.
                    '<input type="text" id="pswd" name="pswd">'.
                    '<input type="submit" value="Change"></form></td>'
                ,'tr');

        $this->p('<tr><td colspan="2">');
        $this->p('Source code: <a href="https://github.com/daniel-kadosh/wos-245-gift-rewards-php" target="_blank">'.
                'Github</a>'
            );
        $this->p( trim(file_get_contents('git-info')) ,'pre' ,true);
        $this->p('</td></tr></table>');
        $this->htmlFooter();
    }

    /**
     * List & edit current alliances.
     */
    public function alliances() {
        $this->htmlHeader('== Alliance list');
        $this->p('<table>');
        $this->p('<td><b>Add Alliance:</b></td><td colspan="2">'.
                    $this->allianceForm('Add').'</td>'
                    ,'tr');
        $this->p('<td><b>Remove Alliance:</b></td>'.
                    '<td>'.$this->menuForm('alliance/Remove','alliance ID').'</td>'.
                    '<td><b><== CAN MESS UP PLAYER DATABASE</b></td>'
                    ,'tr');
        $this->p('</table>');
        $this->p('<table><tr>');
        foreach (array_keys(self::ALLIANCE_COLUMNS) as $colName) {
            $this->p($colName,'th');
        }
        $this->p('<th>Action</th></tr>');
        try {
            $allAlliances = db()
                ->select('alliances')
                ->orderBy('id','asc')
                ->all();
            $n = 0;
            foreach ($allAlliances as $a) {
                $n++;
                $this->p($this->allianceForm('Update',$a),'tr');
            }
            $this->p('</table>');
            if ( $n==0 ) {
                $this->p('No alliances in the database!','p');
            }
            $this->logInfo("Listed $n alliances");

            $this->p("We only need the alliances that our players are in, ".
                    "so that the player list has this information.<br/>".
                    "We can't get this information from WOS APIs :-(", 'p');
        } catch (PDOException $ex) {
            $this->p(__METHOD__.' <b>DB ERROR:</b> '.$ex->getMessage(),'p',true);
        } catch (\Exception $ex) {
            $this->p(__METHOD__.' <b>Exception:</b> '.$ex->getMessage(),'p',true);
        }
        $this->htmlFooter();
    }

    /**
     * Create a new Alliance.
     */
    public function allianceAdd() {
        $this->htmlHeader('== Add Alliance');
        $allianceData = ['id' => null];
        $this->validateAllianceData($allianceData);

        $short_name = $allianceData['short_name'];
        $this->p("Adding alliance name=<b>$short_name</b>",'p',true);
        try {
            // Check for duplicate
            $result = db()
                ->select('alliances')
                ->where(['short_name' => $short_name])
                ->fetchAssoc();
            if (!empty($result)) {
                $this->pDebug('Details',$result);
                $this->pExit('<b>ERROR:</b> Alliance already exists, ignored.',400);
            }
            // All good, insert!
            $result = db()
                ->insert('alliances')
                ->params($allianceData)
                ->execute();
            $allianceData['id'] = db()->lastInsertId();
            $this->p('Added succesfully!','p',true);
            $this->pDebug('Details',$allianceData);
        } catch (PDOException $ex) {
            $this->pExit(__METHOD__.' <b>DB ERROR:</b> '.$ex->getMessage(),500);
        } catch (\Exception $ex) {
            $this->pExit(__METHOD__.' <b>Exception:</b> '.$ex->getMessage(),500);
        }
        $this->htmlFooter();
    }

    /**
     * Update existing Alliance.
     */
    public function allianceUpdate($alliance_id) {
        $this->htmlHeader('== Update Alliance');
        try {
            $id = $this->validateId($alliance_id);
            $allianceData = db()
                ->select('alliances')
                ->find($id);
            if (empty($allianceData)) {
                $this->pExit("Alliance id=$id not found",404);
            }
            $this->validateAllianceData($allianceData);

            // All good, update!
            $this->p("Updating alliance name=<b>".$allianceData['short_name']."</b>",'p',true);
            db()->update('alliances')
                ->params($allianceData)
                ->where(['id' => $id])
                ->execute();
            $this->p('Updated succesfully!','p',true);
            $this->pDebug('Details',$allianceData);
        } catch (PDOException $ex) {
            $this->pExit(__METHOD__.' <b>DB ERROR:</b> '.$ex->getMessage(),500);
        } catch (\Exception $ex) {
            $this->pExit(__METHOD__.' <b>Exception:</b> '.$ex->getMessage(),500);
        }
        $this->htmlFooter();
    }

    /**
     * Remove an Alliance.
     */
    public function allianceRemove($alliance_id) {
        $this->htmlHeader('== Remove Alliance');
        $id = $this->validateId($alliance_id);
        $result = db()
            ->select('alliances')
            ->find($id);
        if (empty($result)) {
            $this->pExit("Alliance id=$id not found",404);
        }
        $this->pDebug('Details',$result);
        $count = $this->deleteById('alliances',$id);
        if ($count == 0) {
            $this->pExit("Could not delete Alliance id=$id ??",404);
        }
        $this->p("REMOVED Alliance succesfully",'p',true);
        $this->htmlFooter();
    }

    /**
     * List players & last gift reward result.
     */
    public function players() {
        $this->htmlHeader('== Player list');
        // Validation
        $sort = strtolower(request()->params('sort','player_name'));
        $dir  = strtolower(request()->params('dir' ,'asc'));
        if ( array_search($sort,self::LIST_COLUMNS,true) === false ) {
            $this->p(" (Ignored invalid sort column $sort)");
            $sort = 'player_name';
        }
        if ( array_search($dir,['asc','desc'],true) === false) {
            $this->p(" (Ignored invalid sort direction $dir)");
            $dir = 'asc';
        }

        // Filters for list
        $urlParams = request()->params();
        unset ($urlParams[0]);
        $this->p('<table><tr><form>');
        $sortParams = array_intersect_key($urlParams,['sort'=>0,'dir'=>1]);
        foreach ($sortParams as $key => $val) {
            // Couldn't find a cleaner way to pass existing sort options in URL
            $this->p(sprintf('<input type="hidden" name="%s" value="%s">',
                            $key, $val ) );
        }
        $this->p('<b>Filters:</b>','td');
        $pe = new playerExtra('',true);
        $filters = [];
        foreach (array_keys(self::FILTER_NAMES) as $col) {
            $val = isset($urlParams[$col]) ? intval($urlParams[$col]) : -1;
            if ($val != -1) {
                if ($col == 'ignore') {
                    $val = ($val!=0 ? 1 : 0);
                }
                $filters[$col] = $val;
            }
            $pe->$col = $val;
            $this->p( sprintf(' %s=%s ',
                                self::FILTER_NAMES[$col],
                                $pe->getHtmlForm($col,true)
                            ), 'td');
        }
        $this->p('<input type="submit" value="Apply">','td');
        $this->p('</form>');
        $this->p(sprintf('<input type="submit" value="Reset" formmethod="get"'.
                            ' onclick="return gotoURL(\'/players%s\')" />',
                            count($sortParams) ? '?'.http_build_query($sortParams) : ''
                        ),'td');
        $this->p('</tr></table>');

        // Assemble headers for table
        $xCols = 0;
        foreach (self::LIST_COLUMNS as $dbField) {
            if (substr($dbField,0,2)=='x:') {
                $xCols++;
            }
        }
        $leftCols = count(self::LIST_COLUMNS) - $xCols + 1;
        $this->p('<table><tr><td colspan="'.$leftCols.'"></td>'.
                    '<td style="text-align: center; border-bottom: 1px solid;" colspan="'.$xCols.'">'.
                    'Fields to manage manually</td><td></td></tr>'
            );
        $this->p('<tr><th width="30">#</th>');
        foreach (self::LIST_COLUMNS as $colName => $dbField) {
            $newDir = 'asc';
            if ( $sort == $dbField ) {
                $newDir = ($dir=='asc' ? 'desc' : 'asc');
            }
            $this->p( sprintf('<a href="/players?sort=%s&dir=%s%s">%s</a>',
                                $dbField,
                                $newDir,
                                count($filters) ? '&'.http_build_query($filters) : '',
                                $colName), 'td');
        }
        $this->p('<th>Actions</th></tr>');

        try {
            // Assemble players array
            #$dbSort = substr($sort,0,2) == 'x:' ? 'player_name' : $sort;
            $allPlayers = db()
                ->select('players')
                #->orderBy("$dbSort COLLATE NOCASE",$dir)
                ->all();
            foreach ($allPlayers as $key => $p) {
                $pe->parseJsonExtra($p['extra']);
                foreach ($filters as $col => $val) {
                    if ($pe->$col != $val) {
                        unset($allPlayers[$key]);
                        continue 2;
                    }
                }
                $allPlayers[$key] = array_merge($p,$pe->getArray(true));
            }
            // Sort with custom order
            if ( substr($sort,0,2)=='x:' ) {
                $sort = substr($sort,2);
            }
            usort($allPlayers, $this->buildSorter( $sort, $dir ));

            // Display
            $n = 1;
            $actionFormat = '<input onclick="return removeConfirm(\'/%s\',\'%s\')" '.
                                'type="submit" value="%s" formmethod="get"/>';
            foreach ($allPlayers as $p) {
                #$this->pDebug('player',$p); break;
                $pe->parseJsonExtra($p['extra']);
                $this->p('<tr><form action="/update/'.$p['id'].'" method="post">');
                $this->p($n++.']','td');
                foreach ( self::LIST_COLUMNS as $col ) {
                    $xcol = substr($col,2);
                    switch ($col) {
                        case 'player_name' :
                            $this->p('<img src="'.$p['avatar_image'].'" width="20"> <b>'.$p[$col].'</b>','td');
                            break;
                        case 'stove_lv' :
                            $this->p((strlen($p['stove_lv_content']) > 6 ?
                                        '<img src="'.$p['stove_lv_content'].'" width="30">' :
                                        'f'.$p[$col]
                                    ), 'td');
                            break;
                        case 'x:alliance_name':
                            $xcol = 'alliance_id';
                        case 'x:comment':
                        case 'x:ignore':
                            $this->p($pe->getHtmlForm($xcol),'td');
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
                $cleanPlayerName = strtr($p['player_name'],'\'" ','___');
                $this->p('<input type="submit" value="Update"></form>'.
                        sprintf($actionFormat,'remove/'.$p['id'],$cleanPlayerName,'Remove').
                        sprintf($actionFormat,'updateFromWOS/'.$p['id'],$cleanPlayerName,'Update from WOS'),
                        'td');
                $this->p('</tr>');
            }
            $this->p('</table>');
            if ( count($allPlayers)==0 ) {
                $this->p('No players in the database!','p');
            }
            $this->logInfo('Listed '.($n-1).' players');
        } catch (PDOException $ex) {
            $this->p(__METHOD__.' <b>DB ERROR:</b> '.$ex->getMessage(),'p');
        } catch (\Exception $ex) {
            $this->p(__METHOD__.' <b>Exception:</b> '.$ex->getMessage(),'p');
        }
        $this->htmlFooter();
    }

    /**
     * Send reward code to all users.
     */
    public function send($giftCode) {
        $this->htmlHeader('== Send Gift Code');
        $this->validateGiftCode($giftCode);
        $this->p("Sending <b>$giftCode</b> to all players that haven't yet received it:",'p');

        // Create initial stub record for this giftcode
        $this->stats = new giftcodeStatistics();
        $startTime = $this->getTimestring(true,true);
        try {
            $gc = db()->select('giftcodes','statistics')
                ->where(['code' => $giftCode])
                ->first();
            if ( !empty($gc) ) {
                $this->stats->parseJsonStatistics($gc['statistics']);
            }
            if ($this->dbg) {
                $this->pDebug('prevStats=',$gc);
            }
            $this->stats->increment('usersSending',$_SERVER['REMOTE_USER']);
            $s = $this->stats->getJson();
            $t = $this->getTimestring(false,false);
            db()->query('INSERT INTO giftcodes(code,created_at,updated_at,statistics) '.
                        'VALUES (?,?,?,?) ON CONFLICT(code) '.
                        'DO UPDATE SET updated_at=?, statistics=?')
                ->bind($giftCode, $t, $t, $s, $t, $s)
                ->execute();
        } catch (PDOException $ex) {
            $this->p('<b>DB WARNING upserting giftcode:</b> '.$ex->getMessage(),'p',true);
        }

        $httpReturnCode = 200;
        $errMsg = [];
        $n = 0; // # of players attempted
        $xrlrPauseTime = 61; // sleep time when reaching x-ratelimit-remaining
        $this->badResponsesLeft = 3; // Max bad responses (network error) from API before abort
        try {
            $allPlayers = db()
                ->select('players')
                ->where('last_message', 'not like', $giftCode.': %')
                ->where('extra', 'not like', '%ignore":1%')
                ->orderBy('id','asc')
                ->all();
            $numPlayers = count($allPlayers);
            $label = 'Try#'.count($this->stats->expected)+1;
            $this->stats->expected[$label] = $numPlayers;
            if ($this->dbg) {
                $this->p(__METHOD__." Found $numPlayers to process",'p',true);
            }
            if ( $numPlayers == 0 ) {
                $errMsg[] = 'No players in the database that still need that gift code.';
                $httpReturnCode = 404;
            }
            if ($this->dbg) {
                $this->p("numPlayers=$numPlayers",'p',true);
            }
            foreach ($allPlayers as $p) {
                usleep(100000); // 100msec slow-down between players
                if ( $this->badResponsesLeft < 1 ) {
                    break;
                }
                $n++;

                // Debug use
                if ($this->guzEmulate && $n>20) break;

                $signInResponse = $this->verifyPlayerInWOS($p);
                if ( is_null($signInResponse) ) {
                    // Do not continue process, API problem
                    $this->updateGiftcodeStats($giftCode);
                    break;
                } else if ( ! $signInResponse['playerGood'] ) {
                    // Invalid sign-in, ignore this player
                    $this->updateGiftcodeStats($giftCode);
                    continue;
                }

                // API ratelimit: assume if it hits 0 we have to wait 1 minute
                $xrlr = $signInResponse['headers']['x-ratelimit-remaining'];
                if ($xrlr < 2 && $n < $numPlayers) {
                    $this->p("(signIn x-ratelimit-remaining=$xrlr - pause $xrlrPauseTime sec) ",0,true);
                    // Proactively sleep here
                    if ( ! $this->guzEmulate ) {
                        sleep($xrlrPauseTime);
                    }
                }

                $giftResponse = $this->send1Giftcode($p['id'],$giftCode);
                if ( $giftResponse == null ) {
                    // Do not continue process, API problem
                    break;
                }

                // API ratelimit: assume if it hits 0 we have to wait 1 minute
                $xrlr = $giftResponse['headers']['x-ratelimit-remaining'];
                if ($xrlr < 2 && $n < $numPlayers) {
                    $this->stats->hitRateLimit++;
                    $this->p("(gift x-ratelimit-remaining=$xrlr - pause $xrlrPauseTime sec) ",0,true);
                    // Proactively sleep here
                    if ( ! $this->guzEmulate && $n < $numPlayers) {
                        sleep($xrlrPauseTime);
                    }
                }
            }
        } catch (PDOException $ex) {
            $errMsg[] = __METHOD__.' <b>DB ERROR:</b> '.$ex->getMessage();
            $httpReturnCode = 500;
        } catch (\Exception $ex) {
            $errMsg[] = __METHOD__.' <b>Exception:</b> '.$ex->getMessage();
            if ($this->dbg) {
                $this->pDebug('exception=',$ex);
            }
            $httpReturnCode = 500;
        }
        if ( $this->badResponsesLeft<1 ) {
            $errMsg[] = 'exceeded max bad responses.';
            $httpReturnCode = 500;
        }
        $this->p("Processed $n players",'p',true);
        $this->stats->runtime = $this->stats->runtime + ($this->getTimestring(true,true) - $startTime);
        $this->updateGiftcodeStats($giftCode);
        if ( $httpReturnCode>200 ) {
            if ( $httpReturnCode>404 ) {
                $errMsg[] = 'Incomplete run!';
            }
            $this->pExit($errMsg,$httpReturnCode);
        }
        $this->p('Send giftcode run completed succesfully!','p',true);
        $this->htmlFooter();
    }

    /**
     * Create a new player.
     */
    public function add($player_id) {
        $this->htmlHeader('== Add player');
        $player_id = $this->validateId($player_id);
        $this->p("Adding player id=$player_id",'p',true);
        try {
            // Check for duplicate before hitting WOS API
            $result = db()
                ->select('players')
                ->find($player_id);
            if (!empty($result)) {
                $this->pDebug('Details',$result);
                $this->pExit('<b>ERROR:</b> player ID already exists, ignored.',400);
            }

            // Verify player exists and is in #245 thru WOS API
            $response = $this->signInWOS($player_id);
            if ($response['err_code'] == 40004) {
                $this->pExit('<b>ERROR:</b> player ID does not exist in WOS, ignored.',404);
            } else if ($response['http-status'] >= 400) {
                $this->pExit('<b>WOS API ERROR:</b> '.$response['guzExceptionMessage'],418);
            } else if ($response['code'] != 0) {
                $this->pExit('<b>WOS API problem:</b> '.$response['err_code'].': '.$response['msg'],418);
            }
            $data = $response['data'];
            if ($data->kid != self::OUR_STATE) {
                $this->pExit('<b>'.$data->nickname.'</b> is in invalid state #'.$data->kid,404);
            }
            // All good, insert!
            $pe = new playerExtra(); // 'extra' field pre-populated with defaults
            $aid = db()
                ->select('alliances','id')
                ->where(['short_name'=>self::OUR_ALLIANCE])  // Default to VHL alliance
                ->first();
            $pe->alliance_id = empty($aid) ? 0 : $aid['id'];
            $t = $this->getTimestring(true,false);
            $playerData = [
                'id'            => $player_id,
                'player_name'   => trim($data->nickname),
                'last_message'  => '(Created)',
                'avatar_image'  => $data->avatar_image,
                'stove_lv'      => $data->stove_lv,
                'stove_lv_content' => $data->stove_lv_content,
                'created_at'    => $t,
                'updated_at'    => $t,
                'extra'         => $pe->getJson()
            ];
            $result = db()
                ->insert('players')
                ->params($playerData)
                ->execute();
            $this->p('Player added into the database: <b>'.$playerData['player_name'].'</b>','p',true);
            $this->pDebug('Details',$playerData);
        } catch (PDOException $ex) {
            $this->pExit(__METHOD__.' <b>DB ERROR:</b> '.$ex->getMessage(),500);
        } catch (\Exception $ex) {
            $this->pExit(__METHOD__.' <b>Exception:</b> '.$ex->getMessage(),500);
        }
        $this->htmlFooter();
    }

    /**
     * Update player data
     */
    public function update($player_id) {
        $this->htmlHeader('== Update player');
        $player_id = $this->validateId($player_id);
        $this->p("Updating player id=$player_id",'p',true);
        try {
            // Check for duplicate before hitting WOS API
            $playerData = db()
                ->select('players')
                ->find($player_id);
            if (empty($playerData)) {
                $this->pExit('<b>ERROR:</b> player ID not found',404);
            }

            // Retrieve POST parameters
            $params = request()->body(true);
            if ( empty($params) ) {
                $this->pExit('<b>ERROR:</b> no data to update',400);
            }
            if ( isset($params['ignore']) ) {
                $i = strtolower(trim($params['ignore']));
                if ( is_int($i) ) {
                    $i = ($i!=0 ? 1 : 0);
                }
                $params['ignore'] = ($i=='on' ? 1 : $i);
            }
            $pe = new playerExtra( json_encode($params, JSON_UNESCAPED_UNICODE), true );
            $data = [
                'updated_at'    => $this->getTimestring(true,false),
                'extra'         => $pe->getJson()
            ];
            $rowsUpdated = db()
                ->update('players')
                ->params($data)
                ->where(['id' => $player_id])
                ->execute()
                ->rowCount();

            // Done, show results!
            if ( $rowsUpdated>0 ) {
                $this->p('Updated: <b>'.$playerData['player_name'].'</b>','p',true);
                unset($playerData['extra']);
                $playerData['updated_at'] = $data['updated_at'];
                $data = array_merge($playerData,$pe->getArray(true));
                $this->pDebug('Details',$data);
            } else {
                $this->pExit('No rows updated for <b>'.$playerData['player_name'].'</b>',500);
            }
        } catch (PDOException $ex) {
            $this->pExit(__METHOD__.' <b>DB ERROR:</b> '.$ex->getMessage(),500);
        } catch (\Exception $ex) {
            $this->pExit(__METHOD__.' <b>Exception:</b> '.$ex->getMessage(),500);
        }
        $this->htmlFooter();
    }

    /**
     * Update player data from WOS
     */
    public function updateFromWOS($player_id) {
        $this->htmlHeader('== Update player from WOS');
        switch ( strtolower(trim($player_id)) ) {
            /* Could be hitting API too much, unnecessarily...
            case 'all':
                $playerIDs = db()
                    ->select('players','id')
                    ->orderBy('id','asc')
                    ->all();
                break;
            */
            case 'ignore':
                $playerIDs = db()
                    ->select('players','id')
                    ->where('extra', 'like', '%ignore":1%')
                    ->orderBy('id','asc')
                    ->all();
                break;
            default:
                $playerIDs = [ $this->validateId($player_id) ];
                break;
        }
        if ( empty($playerIDs) ) {
            $this->pExit('<b>ERROR:</b> '.htmlentities($player_id).' players not found',404);
        }
        if ( isset($playerIDs[0]['id']) ) {
            $playerIDs = array_map( function($v) { return $v['id']; }, $playerIDs);
        }
        $numPlayers = count($playerIDs);
        if ( $numPlayers>1 ) {
            $this->p("Verifying $numPlayers players",'p',true);
        }

        try {
            $this->stats = new giftcodeStatistics(); // won't use, but verifyPlayerInWOS needs it
            $this->badResponsesLeft = 4; // max issues from WOS API before abort
            $xrlrPauseTime = 61;
            $n = 0;
            foreach ($playerIDs as $playerID) {
                usleep(100000); // 100msec slow-down between players
                if ( $this->badResponsesLeft < 1 ) {
                    break;
                }
                $n++;
                if ($numPlayers==1) {
                    $this->p("Updating player data from WOS for id=$player_id",'p',true);
                }
                // Ensure we already have him before hitting WOS API
                $playerData = db()
                    ->select('players')
                    ->find($playerID);
                if (empty($playerData)) {
                    $this->pExit('<b>ERROR:</b> player ID not found',404);
                }

                // Verify in MOS API
                $signInResponse = $this->verifyPlayerInWOS($playerData);
                if ( is_null($signInResponse) ) { // signal we need to abort
                    break;
                }
                if ( $signInResponse['playerGood'] ) {
                    if ($numPlayers==1) {
                        $this->p('verified</p>');
                    } else {
                        $this->p(sprintf('verified! stove_lv=%d</p>', $playerData['stove_lv']),'',true);
                    }
                } // else message about 'deleted' or WOS API problem already given.

                // API ratelimit: assume if it hits 0 we have to wait 1 minute
                $xrlr = $signInResponse['headers']['x-ratelimit-remaining'];
                if ($xrlr < 2 && $n < $numPlayers) {
                    $this->p("(signIn x-ratelimit-remaining=$xrlr - pause $xrlrPauseTime sec) ",0,true);
                    // Proactively sleep here
                    if ( ! $this->guzEmulate ) {
                        sleep($xrlrPauseTime);
                    }
                }
            }
            if ($numPlayers==1) {
                // Dump single player data
                $pe = new playerExtra($playerData['extra'],true);
                unset($playerData['extra']);
                $playerData = array_merge($playerData,$pe->getArray(true));
                $this->pDebug('<b>Results</b>',$playerData);
            } else if ($n==$numPlayers) {
                $this->p('Completed succesfully.','p');
            }
        } catch (PDOException $ex) {
            $this->pExit(__METHOD__.' <b>DB ERROR:</b> '.$ex->getMessage(),500);
        } catch (\Exception $ex) {
            $this->pExit(__METHOD__.' <b>Exception:</b> '.$ex->getMessage(),500);
        }
        $this->htmlFooter();
    }

    /**
     * Remove player.
     */
    public function remove($player_id) {
        $this->htmlHeader('== Remove player');
        $player_id = $this->validateId($player_id);
        $this->p("Removing player id=$player_id",'p',true);
        $result = db()
            ->select('players')
            ->find($player_id);
        if (empty($result)) {
            $this->pExit("Player id=$player_id not found",404);
        }
        $this->pDebug('Details',$result);
        $count = $this->deleteById('players',$player_id);
        if ($count == 0) {
            $this->pExit("Could not delete player id=$player_id ??",404);
        }
        $this->p("REMOVED player ID=$player_id succesfully",'p',true);
        $this->htmlFooter();
    }

    /**
     * List past Giftcodes.
     */
    public function giftcodes() {
        $this->htmlHeader('== Sent Giftcode List');
        $this->p('This is a summary of gift codes sent in the past','p');
        $this->p('<table><tr>');
        foreach (array_keys(self::GIFTCODE_COLUMNS) as $colName) {
            $this->p("<u>$colName</u>",'th');
        }
        $this->p('</tr>');
        try {
            $allGiftcodes = db()
                ->select('giftcodes')
                ->orderBy('id','desc')
                ->limit(50)
                ->all();
            $stats = new giftcodeStatistics();
            foreach ($allGiftcodes as $a) {
                #$this->pDebug('json=',$a['statistics']);
                $stats->parseJsonStatistics($a['statistics']);
                unset($a['statistics']);
                $a = array_merge($a, (array) $stats);
                $this->p('<tr>');
                foreach (self::GIFTCODE_COLUMNS as $col) {
                    $val = $a[$col];
                    $type = is_array($val) ? 'array' : (is_numeric($val) ? 'num' : 'string');
                    switch ($type) {
                        case 'num':
                            $this->p('<td style="text-align: center;">'.$val.'</td>');
                            break;
                        case 'string':
                            $this->p("<b>$val</b>",'td');
                            break;
                        case 'array':
                            $this->p('<td>');
                            foreach ($val as $n => $v) {
                                $this->p(sprintf("%s: %s<br/>",$v,$n));
                            }
                            $this->p('</td>');
                        default:
                            break;
                    }
                }
                $this->p('</tr>');
            }
            $this->p('</table>');
            $n = count($allGiftcodes);
            if ( $n<1 ) {
                $this->p('No sent Giftcodes in the database!','p');
            }
            $this->logInfo("Listed $n Giftcodes");
        } catch (PDOException $ex) {
            $this->p(__METHOD__.' <b>DB ERROR:</b> '.$ex->getMessage(),'p',true);
        } catch (\Exception $ex) {
            $this->p(__METHOD__.' <b>Exception:</b> '.$ex->getMessage(),'p',true);
        }
        $this->htmlFooter();
    }

    /**
     * Download CSV or XLS of database
     */
    public function download($fileFormat = '') {
        $formats = [
            'csv'     => ['ct' => 'text/csv',                 'ext' => 'csv' ],
            'json'    => ['ct' => 'application/json',         'ext' => 'json'],
            'curl'    => ['ct' => 'text/plain',               'ext' => 'sh'  ],
            'sqlite3' => ['ct' => 'application/vnd.sqlite3',  'ext' => 'db'  ]
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
            $this->p(sprintf($lineFormat,'sqlite3','sqlite3',
                'Full Sqlite3 database backup'),'tr');
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

        if ( $format != 'sqlite3' ) {
            // Assemble player array
            $allPlayers = db()
                ->select('players')
                ->orderBy('id','asc')
                ->all();

            $pe = new playerExtra('',true);
            foreach ($allPlayers as $key => $p) {
                $pe->parseJsonExtra($p['extra']);
                unset($p['extra']);
                $allPlayers[$key] = array_merge($p,$pe->getArray(true));
            }
        }

        // PHP to handle output buffering
        ob_start();
        switch ($format) {
            case 'json':
                print "[\n";
                foreach ($allPlayers as $p) {
                    print json_encode($p,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE).",\n";
                }
                print "]\n";
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
                print "#!/bin/bash\n".
                    "# Script to add all users\n".
                    "BASE_URL='".rtrim(_env('APP_URL'),'/')."'\n".
                    "DIGEST_AUTH='username:password'\n".
                    "if [ \"\${DIGEST_AUTH}\" == \"username:password\" ]; then\n".
                    "    echo 'Please edit this file and update with your credentials'\n".
                    "    exit 1\n".
                    "fi\n\n";
                $curlAuth = '--digest -u "${DIGEST_AUTH}"';
                $curlUrl = '${BASE_URL}';
                $n = 1;
                foreach ($allPlayers as $p) {
                    printf("curl -s \"%s/add/%d\" %s | grep -e '^<p>'\n",
                        $curlUrl, $p['id'], $curlAuth);
                    if ( $n++ % 29 == 0) {
                        print "sleep 61\n";
                    }
                }
                break;
            case 'sqlite3':
                readfile( _env('DB_DATABASE') );
                break;
            default:
                break;
        }
        ob_flush();
    }

    /**
     * Admin menu.
     */
    public function admin() {
        $this->htmlHeader('== Admin menu');
        $numUsers = $this->validateDigestFile(); // Go check digest auth file permissions

        $inputFormat = '%s: <input type="text" id="%s" name="%s">';
        $this->p('<ul>');
        $this->p('<b>Add user</b> <form action="/admin/add" method="post">'.
                    sprintf($inputFormat,'Username','username','username').
                    sprintf($inputFormat,'Password','pswd','pswd').
                    '<input type="submit" value="Add"></form>'
                ,'li');
        $this->p('<b>Remove user</b> <form action="/admin/remove" method="post">'.
                    sprintf($inputFormat,'Username','username','username').
                    '<input type="submit" value="Remove"></form>'
                ,'li');
        $this->p('</ul>');

        $this->p("There are $numUsers admin users:",'h4',true);
        $cmd = sprintf('awk -F: \'{if ($2=="%s") {print "<li> &nbsp; <b>"$1"</b></li>"}}\' %s',
                        self::DIGEST_REALM, _env('APACHE_DIGEST') );
        $this->p($this->execCmd($cmd),'ol',true);
        $this->htmlFooter();
    }

    /**
     * Add admin user.
     */
    public function adminChangePassword() {
        $this->htmlHeader('== Change Password');
        $username = $_SERVER['REMOTE_USER'];    // Currently logged user
        $this->p("Changing password for: $username",'p',true);
        $this->validateUsername($username);
        $password = request()->get('pswd');
        $this->validatePassword($password);

        if ( $this->countUsers($username) > 0 ) {
            $linesChanged = $this->changeAdminUserPassword($username,$password);
            if ( $linesChanged > 0 ) {
                $this->p("($linesChanged) Successfuly changed password",'p',true);
            } else {
                $this->p("($linesChanged) Could not change password!",'p',true);
            }
        } else {
            $this->p('User NOT found, impossible!','p',true);
        }
        $this->htmlFooter();
    }

    /**
     * Add admin user.
     */
    public function adminAdd() {
        $this->htmlHeader('== Add Admin User');
        $this->p('<a href="/admin">[Admin Menu]</a>','p');
        $this->validateDigestFile();
        $username = request()->get('username');
        $this->p("Adding admin user $username:",'p',true);
        $this->validateUsername($username);
        $password = request()->get('pswd');
        $this->validatePassword($password);

        if ( $this->countUsers($username) > 0 ) {
            // $this->p('User found, changing password','p',true);
            // $numUser = $this->changeAdminUserPassword($username,$password);
            $this->pExit('User already exists!',409);
        }

        $fullLine = sprintf("%s:%s:%s\n", $username, self::DIGEST_REALM,
            $this->plainPassword2Digest($username,$password) );
        file_put_contents( _env('APACHE_DIGEST'), $fullLine, FILE_APPEND | LOCK_EX);
        $this->logInfo($_SERVER['REMOTE_USER'].' added: '.$fullLine);
        $numUser = $this->countUsers($username);
        if ( $numUser < 1 ) {
            $this->pExit("ERROR: Could not verify new admin user!",500);
        }
        $this->p("Successfuly added $numUser admin users",'p',true);
        $this->htmlFooter();
    }

    /**
     * Remove admin user.
     */
    public function adminRemove() {
        $this->htmlHeader('== Remove Admin User');
        $this->p('<a href="/admin">[Admin Menu]</a>','p');
        $this->validateDigestFile();
        $username = request()->get('username');
        $this->p("Removing admin user $username:",'p',true);
        $this->validateUsername($username);

        if ( $this->countUsers() < 2) {
            $this->pExit('ERROR: Only 1 user, cannot remove all users',403);
        }
        /*  ?? Not sure if we should enforce this...
        if ( $_SERVER['REMOTE_USER'] != $username ) {
            $this->pExit('ERROR: can only remove self, not others. Current user='.$_SERVER['REMOTE_USER'],403);
        }
        */
        // This should never happen if already auth'ed and above "REMOTE_USER==username" check passed
        if ( $this->countUsers($username) != 1 ) {
            $this->pExit('ERROR: Username not found',404);
        }

        $numDeleted = $this->deleteAdminUser($username);
        if ( $numDeleted < 1 ) {
            $this->pExit("ERROR: Could not remove admin user",500);
        }
        $this->p("Successfuly removed $numDeleted admin users",'p',true);
        $this->htmlFooter();
    }

    ///////////////////////// Helper functions
    private function deleteAdminUser($username) {
        $l = intval( $this->execCmd('wc -l '._env('APACHE_DIGEST')) );
        $this->execCmd( sprintf("sed -i '/^%s:%s:/d' %s",
                                $username,
                                self::DIGEST_REALM,
                                 _env('APACHE_DIGEST')
                        ) );
        return $l - intval( $this->execCmd('wc -l '._env('APACHE_DIGEST')) );

    }
    private function plainPassword2Digest($username,$password) {
        return md5(sprintf('%s:%s:%s', $username, self::DIGEST_REALM, $password ));
    }
    private function changeAdminUserPassword($username,$password) {
        return intval( $this->execCmd(
            sprintf('perl -i -lpe \'$k+= s/^%s:%s:.*$/%s:%s:%s/g; END{print "$k"}\' %s',
                $username,
                self::DIGEST_REALM,
                $username,
                self::DIGEST_REALM,
                $this->plainPassword2Digest($username,$password),
                _env('APACHE_DIGEST')
            ) ) );
    }
    private function countUsers($userToFind=null) {
        return intval($this->execCmd( sprintf('grep -c %s:%s: %s',
                                (empty($userToFind) ? '' : '^'.$userToFind),
                                self::DIGEST_REALM,
                                _env('APACHE_DIGEST')
                            ) ));
    }
    private function execCmd($cmd) {
        if ($this->dbg) {
            $this->logInfo("--Executing: ".$cmd);
        }
        return `$cmd`;
    }
    private function validateUsername($username) {
        $errMsg = [];
        if ( empty($username) ) {
            $errMsg[] = 'No Username received';
        } else {
            if ( ! ctype_alnum($username) ) {
                $errMsg[] = 'Username can only have alphanumeric characters';
            }
            $l = strlen($username);
            if ( $l<4 || $l>15 ) {
                $errMsg[] = "Username has $l characters and must be between 4 and 15";
            }
        }
        if ( count($errMsg) == 0 ) {
            return true;
        }
        $this->pExit($errMsg,400);
    }
    private function validateDigestFile() {
        $f = _env('APACHE_DIGEST');
        $ret = 0;
        $errMsg = [];
        if ( empty($f) || strlen($f) < 2 ) {
            $errMsg[] = 'Config error: Set APACHE_DIGEST in .env file to digest passwd filename';
            $ret = 500;
        } else {
            try {
                $read  = is_readable($f) ? null : 'read';
                $write = is_writable($f) ? null : 'write';
                if ( $read || $write ) {
                    $errMsg[] = "Config error: Cannot [$read $write] APACHE_DIGEST file $f";
                    $ret = 500;
                }
                // Security safeguard: must have configured app in running environment first, before letting
                // this web app act on the htdigest file
                if ( ! $read ) {
                    $numUsers = $this->countUsers();
                    if ( $numUsers < 1) {
                        $errMsg[] = 'Config error: use "wos.sh -u USERNAME" to create at least 1 admin user '.
                                    'in realm '.self::DIGEST_REALM;
                        $ret = 500;
                    }
                }
            } catch (\Exception $ex) {
                $errMsg[] = 'EXCEPTION: '.$ex->getMessage();
                $ret = 500;
            }
        }
        if ($ret == 0) {
            return $numUsers;
        }
        $this->pExit($errMsg,$ret);
    }
    private function validatePassword($password) {
        $errMsg = [];
        if ( empty($password) ) {
            $errMsg[] = 'No Password received';
            $ret = 400;
        } else {
            if ( ! preg_match('/^[A-Za-z0-9\_\-!@#\$%^&*()=+|{}<>?]+$/',$password) ) {
                $errMsg[] = 'Invalid password: allowed characters: A-Z a-z 0-9 _-!@#$%^&*()=+|{}<>?';
            }
            $len = strlen($password);
            if ( $len<8 || $len>25 ) {
                $errMsg[] = "Invalid password: length=$len, should be between 8 and 25";
            }
        }
        if (count($errMsg)) {
            $this->pExit($errMsg, 400);
        }
        return true;
    }
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
        $this->pExit('PlayerID failed validation: '.$player_id,400);
    }
    private function validateGiftCode($giftCode) {
        $giftCode = trim($giftCode);
        if (!empty($giftCode) && strlen($giftCode)>4) {
            if (!is_integer($giftCode) && !strpbrk($giftCode,' _/\\|}{][^$')) {
                return $giftCode;
            }
        }
        $this->pExit('Improper Gift Code '.$giftCode,400);
    }
    private function validateAllianceData(&$existingAllianceData) {
        $lengths = [
            'short_name' => [3,3],
            'long_name'  => [3,15],
            'comment'    => [0,30]
        ];
        $body = request()->body(true);
        $errMsg = [];
        $paramsFound = 0;
        foreach ($lengths as $field => $lengths) {
            if ( isset($body[$field]) ) {
                $v = trim($body[$field]);
                $l = strlen($v);
                if ($l<$lengths[0] || $l>$lengths[1]) {
                    $errMsg[] = sprintf("$field was $l characters long, needs to be between %d and %d",
                            $lengths[0], $lengths[1]);
                } else {
                    $paramsFound++;
                    $existingAllianceData[$field] = $v;
                }
            } else if ( ! isset($existingAllianceData[$field]) ) {
                $existingAllianceData[$field] = '';
            }
        }
        if ( $paramsFound == 0 ) {
            $errMsg[] = 'No valid parameters received in request body';
        }
        if ( ! empty($errMsg) ) {
            $this->pExit($errMsg,400);
        }
    }
    private function deleteById($table,$id) {
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
    private function verifyPlayerInWOS( &$p ) {
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
            $this->p('<b>ABORT:</b> Failed to sign in player</p>',0,true);
            return null;
        }
        $sd = $signInResponse['data'];
        $signInResponse['playerGood'] = true;
        $stateID = isset($sd->kid) ? $sd->kid : -1;
        if ($signInResponse['err_code'] == 40004 || $stateID !=self::OUR_STATE ) {
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

    private function send1Giftcode($playerId,$giftCode) {
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
                db()->update('players')
                    ->params([
                        'last_message'  => $msg,
                        'updated_at'    => $this->getTimestring(true,false)
                    ])
                    ->where(['id' => $playerId])
                    ->execute();
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
            default:
                $msg = "$giftErrCode ".$giftResponse['msg'];
                $this->stats->increment('giftErrorCodes',$msg);
                break;
        }
        $this->p("$msg</p>",0,true);
        db()->update('players')
            ->params([
                'last_message'  => $msg,
                'updated_at'    => $this->getTimestring(true,false)
            ])
            ->where(['id' => $playerId])
            ->execute();
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

    private function updateGiftcodeStats($code) {
        try {
            $rowsUpdated = db()
                    ->update('giftcodes')
                    ->params([
                        'updated_at' => $this->getTimestring(true,false),
                        'statistics' => $this->stats->getJson()
                    ])
                    ->where(['code' => $code])
                    ->execute()
                    ->rowCount();
        } catch (PDOException $ex) {
            $this->p('<b>DB ERROR updating giftcodes:</b> '.$ex->getMessage(),'p',true);
        }
    }

    ///////////////////////// Guzzle functions
    private function signInWOS($fid) {
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

    private function sendGiftCodeWOS($fid, $giftCode) {
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

    private function getTimestring($renew=false,$inUnixTime=true) {
        if (empty($this->time) || $renew) {
            $this->time = tick('now');
        }
	    return (string) $this->time->format($inUnixTime ? 'U':
                'YYYY-MM-DD HH:mm:ss');
    }

    ///////////////////////// View functions
    private function htmlHeader($title=null) {
        $this->p('<html><head>');
        $this->p('<meta name="robots" content="noindex,nofollow" />');
        $this->p("
            th, td { padding: 2px; text-align: left; vertical-align: middle; }
            a { font-weight: bold; }');
            th { text-decoration: underline; }');
            button { background-color: #ADD8E6; font-weight: bold; }');\n",
            'style');
        $this->p("<script type=\"text/javascript\">
            function removeConfirm(url,name) {
                if (confirm(`\${url} \${name}\nAre you sure?`)) {
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
                removeConfirm(url,'');
                return false;
            }
            function gotoURL(url) {
                location.href = url;
            }");
        $this->p('</script>');
        $this->p('</head><body style="background-color:#D3D3D3;">');
        $this->p("WOS #245 Gift Rewards",'h1');
        if ( $this->dbg || $this->guzEmulate ) {
            $this->p(__CLASS__.': dbg='.($this->dbg?1:0).' guzEmulate='.($this->guzEmulate?1:0),'pre',true);
        }

        $this->p('<table><tr>');
        $this->p('<a href="/">Home</a>','td');
        $this->p('<b>|</b> <a href="/alliances">Alliances</a>','td');
        $this->p('<b>|</b> <a href="/players">Players</a>','td');
        $this->p('<b>|</b> <a href="/giftcodes">Giftcodes</a>','td');
        $this->p('<b>|</b> <a href="/download">Download</a>','td');
        $this->p('<td width="5">&nbsp;</td>');
        $this->p($this->menuForm('Add','player ID','','['.self::OUR_ALLIANCE.'] '),'td');
        $this->p($this->menuForm('Remove','player ID','',' <b>||</b> '),'td');
        $this->p($this->menuForm('Send','gift code','Send Giftcode',' <b>||</b> '),'td');
        $this->p('</tr></table>');
        if ($title) {
            $this->p($title,'h3');
        }
    }
    private function htmlFooter() {
        $this->p('</body></html>');
    }
    private function menuForm($action,$placeHolder,$buttonName='',$label='') {
        $lAction = strtolower($action);
        if (empty($buttonName)) {
            $buttonName = $action;
        }
        $idField = $lAction.'Id';
        if ( ! empty($placeHolder) ) {
            $placeHolder = sprintf(' placeholder="%s"', $placeHolder);
        }
        if ( ! empty($label) ) {
            $label = sprintf('<label for="%s">%s</label>', $idField, $label);
        }
        return sprintf('<form onsubmit="return formConfirm(\'%s\',\'%s\');">%s'.
                    '<input type="text" id="%s" name="%s" size="10"%s>'.
                    '<button value="%s">%s</button>'.
                    '</form>',
                    $lAction, $idField, $label,
                    $idField, $idField, $placeHolder,
                    $action, $buttonName
                );
    }
    private function allianceForm($action,$data=[]) {
        static $aFields = [
            'short_name' => ['3-letter Name', 7  ],
            'long_name'  => ['Long Name',     15 ],
            'comment'    => ['Comment',       30 ]
        ];
        $aId = empty($data['id']) ? 0 : $data['id'];
        $ret = sprintf('<form action="/alliance/%s%s" method="post">%s',
                        strtolower($action),
                        $aId ? "/$aId" : '',
                        $aId ? "<td>$aId</td>\n" : "\n"
                    );
        foreach ($aFields as $field => $fieldInfo) {
            $ret .= sprintf('%s<input placeholder="%s" type="text" id="%s" name="%s" size="%d"%s>%s',
                        $aId ? '<td>' : '',
                        $fieldInfo[0], $field, $field, $fieldInfo[1],
                        empty($data[$field]) ? '' : ' value="'.$data[$field].'"',
                        $aId ? "</td>\n" : "\n"
                    );
        }
        return $ret.sprintf('%s<input type="submit" value="%s"></form>%s',
                    $aId ? "<td>" : '',
                    $action,
                    $aId ? "</td>" : "\n"
                );
    }
    private function p($msg,$htmlType=null,$log=false) {
        $format = ( empty($htmlType) ? "%s\n" : "<$htmlType>%s</$htmlType>\n" );
        response()->markup( sprintf($format,$msg) );
        if ($log) {
            $this->logInfo($msg);
        }
    }
    private function pDebug($msg,$text) {
        $this->p("$msg: ".print_r($text,true),'pre',true);
    }
    private function pExit($msg,$httpReturnCode) {
        $lines = is_array($msg) ? $msg : [$msg];
        $this->p('<p>');
        foreach ($lines as $l ) {
            $this->p('<b>ABORT:</b> '.$l.'<br/>','',true);
        }
        $this->p('</p>');
        $this->htmlFooter();
        response()->exit('',$httpReturnCode);
    }
    private function logInfo($msg) {
        static $myPid = getmypid();
        $this->log->info( "$myPid) ".str_replace("\n"," ",trim(strip_tags($msg))) );
    }

    public function buildSorter($key, $dir) {
        // Handle asc vs. desc order with multiplier
        $multiplier = ($dir=='asc' ? 1 : -1);
        return function ($a, $b) use ($key,$multiplier) {
            if ($key=='player_name') {
                // Force case-INsensitive
                return strnatcmp(strtolower($a['player_name']), strtolower($b['player_name'])) * $multiplier;
            }
            $ret = strnatcmp($a[$key], $b[$key]) * $multiplier;
            if ($ret!=0) {
                // Put blank fields at the bottom of the list
                if ($multiplier>0 && (empty($a[$key]) || empty($b[$key])) ) {
                    return -$ret;
                }
                return $ret;
            }
            // 2nd sort key: player_name ASC
            return strnatcmp(strtolower($a['player_name']), strtolower($b['player_name']));
        };
    }
}

class playerExtra {
    public $alliance_id = 0;
    public $comment     = '';
    public $ignore      = 0;
    #public $rank        = 0;
    #public $power;

    const F_STRING   = 1;
    const F_INT      = 2;
    const F_RANK     = 3; // numbers 1-5
    const F_ALLIANCE = 4; // "join" with alliance_id from database
    const F_BOOLEAN  = 6;

    private $log;
    private $fields = [
        'alliance_id'   => self::F_ALLIANCE,
        'comment'       => self::F_STRING,
        'ignore'        => self::F_BOOLEAN
        #'rank'          => self::F_RANK
        #'power'         => self::F_INT
    ];
    private $alliances; // Valid values for alliance_id & name
    private $ranks;     // Valid values for rank (R1-R5)

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
        /*
        $this->ranks[0] = '-';
        for ($i=1; $i<6; $i++) {
            $this->ranks[$i] = "R$i";
        }
        */
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
            #case self::F_RANK:
            #    $options = &$this->ranks;
            #    break;
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
            default:
                return "Unknown field type $type for field $field";
        }
        // Drop-down selection:
        $ret = sprintf('<select name="%s" id="%s%s">',
            $field, ($isFilter ? 'f:' : ''), $field );
        $targetId = (isset($options[$this->$field]) ? $this->$field : 0);
        foreach ($options as $id => $name) {
            $ret .= sprintf( '<option value="%d"%s>%s</option>',
                        $id,($id==$targetId ? ' selected' : ''),$name );
        }
        $ret .= '</select>';
        return $ret;
    }

    /**
     * Set or replace object values
     * @param string $extra     JSON string stored in 'extra' DB column
     */
    public function parseJsonExtra($extra) {
        // First reset everything to defaults
        foreach ($this->fields as $field => $type) {
            $this->$field = ($type==self::F_STRING ? '' : 0);
        }
        if (empty($extra)) {
            return;
        }
        try {
            $x = json_decode($extra);
            foreach ($x as $name => $value) {
                $type = $this->fields[$name];
                $this->$name = ($type==self::F_STRING ? trim($value) : (int) $value);
            }
        } catch (\Exception $e) {
            $this->log->info(__METHOD__.' Exception: '.$e->getMessage());
            $this->log->info('extra='.$extra);
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
            $a[$field] = ($type==self::F_STRING ? $this->$field : (int) $this->$field);
        }
        return $a;
    }
}

/**
 * Manage giftcode statistics
 */
class giftcodeStatistics {
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
        if ( empty($this->$varName[$key]) ) {
            $this->$varName[$key] = 1;
        } else {
            $this->$varName[$key]++;
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
        } catch (\Exception $e) {
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

### For logging, default writer=Leaf\LogWriter Object
( [logFile:protected] => /var/www/app/controllers/../../wos245/wos_controller_2024-07.log )
*/
