<?php

namespace App\Controllers;

use App\Helpers\WosCommon;
use App\Models\GiftcodeStatistics;
use App\Models\PlayerExtra;
use Exception;
use Leaf\Controller;
use Leaf\Http\Request;
use PDOException;

#declare(ticks=1);

class WosController extends Controller {
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
    const GIFTCODE_COLUMNS_STATS = [
            'ID'                => 'id',
            'First Sent UTC'    => 'created_at',
            'Giftcode'          => 'code',
            'Status'            => ':State',
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
        const GIFTCODE_COLUMNS = [
            'ID'                => 'id',
            'Last Sent UTC'     => 'updated_at',
            'Giftcode'          => ':Code',
            'Status'            => ':State',
            'WebUser'           => 'usersSending',
            'Succesful'         => 'succesful',
            'Had Gift'          => 'alreadyReceived',
            'Expected'          => 'expected',
            'Deleted Players'   => 'deletedPlayers',
        ];

    private $wos;

    public function __construct() {
        // Init the framework
        parent::__construct();
        $this->request = new Request;
        $this->wos = new WosCommon();

        // Determine OUR_ALLIANCE from URL hostname (1st string in FQDN)
        $fqdn_parts = explode('.', strtolower($_SERVER['HTTP_HOST']));
        $ourAlliance = array_keys($this->wos->host2Alliance)[0];    // Default to 1st alliance
        foreach ($this->wos->host2Alliance as $alliance => $hostnames) {
            if ( in_array($fqdn_parts[0], $hostnames) ) {
                $ourAlliance = $alliance;
                break;
            }
        }
        // This will finalize configs for logging, database
        $this->wos->setAllianceState($ourAlliance);

        // If Apache's digest auth didn't set REMOTE_USER, we have no auth
        if ( empty($_SERVER['REMOTE_USER']) ) {
            $this->pExit('Auth failure, misconfiguration?',403);
        }
        $this->wos->logInfo('=== '.$this->request->getUrl().$_SERVER['REQUEST_URI'].'  user='.$_SERVER['REMOTE_USER']);

        #phpinfo(INFO_ALL);
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
            'Player list','Have to manually add players knowing their WOS player ID<br/>'.
            'Can filter &amp; sort list, and easily update &amp; remove 1 player at a time.'),'tr');
        $this->p(sprintf($lineFormat,'send/','send/','[giftcode]',
            'Send a reward','to send ALL players the giftcode if they don\'t have it yet.'.
            '<br/><b>NOTE:</b> page will take 2-5 minutes to show anything, let it run and wait!'.
            '<br/>Player will be verified with WOS and <b>DELETED</b> if not found or not in state #'.
            $this->wos->ourState.'.'),'tr');
        $this->p(sprintf($lineFormat,'add/','add/','[playerID]',
            'Add a player','Will get basic player info from WOS and check they are in state #'.$this->wos->ourState.
            '.<br/>By default will add in alliance ['.$this->wos->ourAlliance.'] but you can change afterwards.'),'tr');
        $this->p(sprintf($lineFormat,'remove/','remove/','[playerID]',
            'Remove a player','If you change your mind after removing, just add again <b>;-)</b>'),'tr');
        $this->p(sprintf($lineFormat,'updateFromWOS/','updateFromWOS/','[playerID|ignore]',
            'Revalidate with WOS','Updates player metadata (name, furnace, etc.) with WOS API.<br/>'.
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
            $this->wos->logInfo("Listed $n alliances");

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
            $id = $this->validateID($alliance_id);
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
        $id = $this->validateID($alliance_id);
        $result = db()
            ->select('alliances')
            ->find($id);
        if (empty($result)) {
            $this->pExit("Alliance id=$id not found",404);
        }
        $this->pDebug('Details',$result);
        $count = $this->wos->deleteByID('alliances',$id);
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
        /////////////////////// Validation
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

        ////////////////////// Filters for list
        $urlParams = request()->try(['sort','dir','alliance_id','ignore'],true);
        unset ($urlParams[0]);
        $this->p('<table><tr><form>');
        $sortParams = array_intersect_key($urlParams,['sort'=>0,'dir'=>1]);
        foreach ($sortParams as $key => $val) {
            // Couldn't find a cleaner way to pass existing sort options in URL
            $this->p(sprintf('<input type="hidden" name="%s" value="%s">',
                            $key, $val ) );
        }
        $this->p('<b>Filters:</b>','td');
        $pe = new PlayerExtra('',true);
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

        /////////////// Notes
        $this->p('<ul>');
        $this->p("<b>Ignore</b> = Keep player in the database, but don't send them a gift code.",'li');
        $this->p("<b>Action buttons</b> = Will act on only 1 player, this UI is crude and simple ;-)",'li');
        $this->p('</ul>');

        /////////////// Headers for table
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

        /////////////////// Main table
        try {
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
            $this->wos->logInfo('Listed '.($n-1).' players');
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
        $this->p("Preparing to send '<b>$giftCode</b>' to all players that haven't yet received it",'p',true);

        try {
            $gc = db()->select('giftcodes')
                ->where(['code' => $giftCode])
                ->first();
            $numPlayers = $this->wos->getPlayersForGiftcode( empty($gc) ? null : $giftCode );

            if ( empty($gc) ) {
                // NEW GiftCode: Start the process
                //
                // send_gift_ts: 0=daemon NOT processing, otherwise Unixtime of when it started
                // pct_done: -1=signal for daemon to start, 0-99=processing, 100=done.
                //           Truly completed: pct_done=100 + send_gift_ts=0
                //
                $this->wos->stats = new GiftcodeStatistics();
                $this->wos->stats->increment('usersSending',$_SERVER['REMOTE_USER']);
                $t = $this->wos->getTimestring(true,false); // Formatted time
                db()->insert('giftcodes')
                    ->params(['code'        => $giftCode,
                              'created_at'  => $t,
                              'updated_at'  => $t,
                              'statistics'  => $this->wos->stats->getJson(),
                              'send_gift_ts'=> 0,
                              'pct_done'    => -1  // Tell daemon to start processing
                            ])
                    ->execute();
                if ($this->wos->dbg) {
                    $this->pDebug('GiftCode object',$gc);
                }

                // Really done here, so perhaps refresh to keep updating screen?
                $this->p("QUEUED New gift code for processing of $numPlayers players.",'p',true);
                #$this->htmlFooter();
                #return;
            } else {
                // Existing GiftCode cases:
                // 1) Daemon hasn't started
                //       Check if it's taking too long (pct_done=-1 long time) and tell user if so
                //       ?? Consider it might still be processing a DIFFERENT giftcode
                //       Tell user to wait
                // 2) Daemon in process, still running
                //       Just display progress
                // 3) Daemon quit mid-process
                //       Go ahead and restart
                // 4) Daemon already completed successfully
                //       Check if more (new) users would get giftcode, and restart if so
                // 5) Giftcode is expired or invalid
                //       Do nothing
                //
                if ($this->wos->dbg) {
                    $this->pDebug('GiftCode object',$gc);
                }
                $this->wos->stats = new GiftcodeStatistics($gc['statistics']);
                $origNumPlayers = empty($this->wos->stats->expected) ?
                                    $numPlayers : end($this->wos->stats->expected);

                if ( ! empty($this->wos->stats->expected) ) {
                    $this->p(sprintf('%d previous runs: %d succesful redemptions, %d had already redeemed.',
                                count($this->wos->stats->expected),
                                $this->wos->stats->succesful,
                                $this->wos->stats->alreadyReceived)
                            ,'p');
                }
                $t = $this->wos->getTimestring(true); // Unixtime
                $timeSinceUpdate = $t - tick($gc['updated_at'])->format('U');
                $updateHMS       = gmdate("H:i:s", $timeSinceUpdate);
                $timeSinceStart  = $t - $gc['send_gift_ts'];
                $startHMS        = gmdate("H:i:s", $timeSinceStart);
                $pctDone         = $gc['pct_done'].'%';

                $resetPctDone = false;
                $statusMsg = $this->wos->stats->stateOfGiftCodeHTML($gc);
                switch ( GiftcodeStatistics::stateOfGiftCode($gc) ) {
                    case GiftcodeStatistics::GC_QUEUED:     // 1) hasn't started
                        $this->p("$statusMsg $updateHMS ago. <br/>Hasn't started processing, expecting $numPlayers players.",'p',true);
                        break;
                    case GiftcodeStatistics::GC_RUNNING:    // 2) still processing
                        $this->p("$statusMsg at $startHMS <br/>with $numPlayers/$origNumPlayers players.",'p',true);
                        break;
                    case GiftcodeStatistics::GC_QUIT:       // 3) Quit mid-process, restart if users to process
                        $timeSinceUpdate = 0;
                        $resetPctDone = ($numPlayers>0 ? -1 : 100);
                        $msg = "$statusMsg for $origNumPlayers players";
                        break;
                    case GiftcodeStatistics::GC_DONE:       // 4) Already done, restart if users to process
                        $timeSinceUpdate = 0;
                        $resetPctDone = ($numPlayers>0 ? -1 : 100);
                        $msg = "$statusMsg and fully completed";
                        break;
                    case GiftcodeStatistics::GC_EXPIRED:       // 4) Already done, restart if users to process
                        $timeSinceUpdate = 0;
                        $this->p("$statusMsg or invalid gift code as of last check on ".$gc['updated_at'],'p',true);
                        break;
                    default:
                        $timeSinceUpdate = 0;
                        $this->pDebug("$statusMsg state of giftcode!",$gc);
                        break;
                }
                if ( $timeSinceUpdate >= 300 ) { // 5min is too long to wait
                    $this->p("WARNING: It's been too long, something is probably wrong with the giftcode daemon!",'p',true);
                } else if ( $resetPctDone ) {
                    $this->p(sprintf('%s.<br/>%s.', $msg,
                                $numPlayers>0 ? "Re-QUEING job for $numPlayers players" :
                                                "Currently no players need it")
                            ,'p',true);
                    $this->wos->updateGiftcodeStats($giftCode,$resetPctDone,0);
                }
            }
        } catch (PDOException $ex) {
            $this->p(__METHOD__.' <b>DB WARNING upserting giftcode:</b> '.$ex->getMessage(),'p',true);
        }
        $this->htmlFooter();
    }

    /**
     * Create a new player.
     */
    public function add($player_id) {
        $this->htmlHeader('== Add player');
        $player_id = $this->validateID($player_id);
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
            $response = $this->wos->signInWOS($player_id);
            if ($response['err_code'] == 40004) {
                $this->pExit('<b>ERROR:</b> player ID does not exist in WOS, ignored.',404);
            } else if ($response['http-status'] >= 400) {
                $this->pExit('<b>WOS API ERROR:</b> '.$response['guzExceptionMessage'],418);
            } else if ($response['code'] != 0) {
                $this->pExit('<b>WOS API problem:</b> '.$response['err_code'].': '.$response['msg'],418);
            }
            $data = $response['data'];
            if ($data->kid != $this->wos->ourState) {
                $this->pExit('<b>'.$data->nickname.'</b> is in invalid state #'.$data->kid,404);
            }
            // All good, insert!
            $pe = new PlayerExtra(); // 'extra' field pre-populated with defaults
            $aid = db()
                ->select('alliances','id')
                ->where(['short_name'=>$this->wos->ourAlliance])  // Default to VHL alliance
                ->first();
            $pe->alliance_id = empty($aid) ? 0 : $aid['id'];
            $t = $this->wos->getTimestring(true,false);
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
        $player_id = $this->validateID($player_id);
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
            $pe = new PlayerExtra( json_encode($params, JSON_UNESCAPED_UNICODE), true );
            $data = [
                'updated_at'    => $this->wos->getTimestring(true,false),
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
                $playerIDs = [ $this->validateID($player_id) ];
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
            $this->wos->stats = new GiftcodeStatistics(); // won't use, but verifyPlayerInWOS needs it
            $this->wos->badResponsesLeft = 4; // max issues from WOS API before abort
            $xrlrPauseTime = 61;
            $n = 0;
            foreach ($playerIDs as $playerID) {
                usleep(100000); // 100msec slow-down between players
                if ( $this->wos->badResponsesLeft < 1 ) {
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
                $signInResponse = $this->wos->verifyPlayerInWOS($playerData);
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
                    if ( ! $this->wos->guzEmulate ) {
                        sleep($xrlrPauseTime);
                    }
                }
            }
            if ($numPlayers==1) {
                // Dump single player data
                $pe = new PlayerExtra($playerData['extra'],true);
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
        $player_id = $this->validateID($player_id);
        $this->p("Removing player id=$player_id",'p',true);
        $result = db()
            ->select('players')
            ->find($player_id);
        if (empty($result)) {
            $this->pExit("Player id=$player_id not found",404);
        }
        $this->pDebug('Details',$result);
        $count = $this->wos->deleteByID('players',$player_id);
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
        /////////////////////// URL param validation
        $num = intval( request()->params('num',10) );
        if ( $num<0  ) $num=1;
        if ( $num>200) $num=200;
        $fullstats = intval(request()->params('fullstats',0));
        if ( $fullstats!=0 ) $fullstats=1;
        $gcColumns = ( $fullstats ? self::GIFTCODE_COLUMNS_STATS : self::GIFTCODE_COLUMNS );

        ////////// Filters
        $this->p('<table><tr><form>');
        $this->p('<b>Filters:</b>','td');
        $f = 'Statistics=<select name="fullstats" id="f:fullstats">';
        foreach (['brief','full'] as $n => $val) {
            $f .= sprintf('<option value="%d"%s>%s</option>',
                    $n, ($n==$fullstats ? ' selected' : ''), $val);
        }
        $this->p("$f</select>",'td');
        $this->p(sprintf('Number to list=<input type="text" id="num" name="num" size="3" value="%d">',
                    $num),'td');
        $this->p('<input type="submit" value="Apply">','td');
        $this->p('</form>');
        $this->p('<input type="submit" value="Reset" formmethod="get"'.
                            ' onclick="return gotoURL(\'/giftcodes\')" />'
                        ,'td');
        $this->p('</tr></table>');

        //////// Main table
        $this->p('<table><tr>');
        foreach (array_keys($gcColumns) as $colName) {
            $this->p("<u>$colName</u>",'th');
        }
        $this->p('</tr>');
        try {
            $query = db()
                ->select('giftcodes')
                ->orderBy('id','desc');
            if ( $num ) {
                $query = $query->limit($num);
            }
            $allGiftcodes = $query->all();
            $stats = new GiftcodeStatistics();
            $i=0;
            foreach ($allGiftcodes as $gc) {
                #if ($i++==0 || $gc['pct_done']<100) $this->pDebug('First=',$gc);
                $stats->parseJsonStatistics($gc['statistics']);
                unset($gc['statistics']);
                $gc = array_merge($gc, (array) $stats);
                $this->p('<tr>');
                foreach ($gcColumns as $col) {
                    if ( substr($col,0,1)==':' ) {
                        $type = $col;
                    } else {
                        $val = $gc[$col];
                        $type = is_array($val) ? 'array' : (is_numeric($val) ? 'num' : 'string');
                    }
                    switch ($type) {
                        case ':Code':
                            $val = $gc['code'];
                            if ( $gc['pct_done']>=0 && $gc['pct_done']<=100 ) {
                                $val = sprintf('<a href="/send/%s">%s</a>', $val, $val);
                            }
                            $this->p("<b>$val</b>",'td');
                            break;
                        case ':State':
                            $this->p($stats->stateOfGiftCodeHTML($gc, $fullstats),'td');
                            break;
                        case 'string':
                            $this->p("<b>$val</b>",'td');
                            break;
                        case 'num':
                            $this->p('<td style="text-align: center;">'.$val.'</td>');
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
            $this->wos->logInfo("Listed $n Giftcodes");
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
                        substr($this->wos->getTimestring(true,false),0,10),
                        $formats[$fileFormat]['ext']
                    )
            ])->sendHeaders();

        if ( $format != 'sqlite3' ) {
            // Assemble player array
            $allPlayers = db()
                ->select('players')
                ->orderBy('id','asc')
                ->all();

            $pe = new PlayerExtra('',true);
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
        $this->wos->logInfo($_SERVER['REMOTE_USER'].' added: '.$fullLine);
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
        if ($this->wos->dbg) {
            $this->wos->logInfo("--Executing: ".$cmd);
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
    private function validateID($player_id) {
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
        $this->p(sprintf('WOS #245 Gift Rewards <span style="color:#0000c0">[%s]%s</span>',
            $this->wos->ourAlliance,$this->wos->ourAllianceLong),'h1');
        if ( $this->wos->dbg || $this->wos->guzEmulate ) {
            $this->p('<span style="color:#007000">');
            $this->p('Default alliance='.$this->wos->ourAlliance.' state #'.$this->wos->ourState.' dataDir='.$this->wos->dataDir.
                        "\n".__CLASS__.': dbg='.($this->wos->dbg?1:0).' guzEmulate='.($this->wos->guzEmulate?1:0),'pre',true);
            $this->p('</span>');
        }

        $this->p('<table><tr>');
        $this->p('<a href="/">Home</a>','td');
        $this->p('<b>|</b> <a href="/alliances">Alliances</a>','td');
        $this->p('<b>|</b> <a href="/players">Players</a>','td');
        $this->p('<b>|</b> <a href="/giftcodes">Giftcodes</a>','td');
        $this->p('<b>|</b> <a href="/download">Download</a>','td');
        $this->p('</tr></table><table></tr>');
        $this->p($this->menuForm('Add','player ID','','['.$this->wos->ourAlliance.'] '),'td');
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
        $aID = empty($data['id']) ? 0 : $data['id'];
        $ret = sprintf('<form action="/alliance/%s%s" method="post">%s',
                        strtolower($action),
                        $aID ? "/$aID" : '',
                        $aID ? "<td>$aID</td>\n" : "\n"
                    );
        foreach ($aFields as $field => $fieldInfo) {
            $ret .= sprintf('%s<input placeholder="%s" type="text" id="%s" name="%s" size="%d"%s>%s',
                        $aID ? '<td>' : '',
                        $fieldInfo[0], $field, $field, $fieldInfo[1],
                        empty($data[$field]) ? '' : ' value="'.$data[$field].'"',
                        $aID ? "</td>\n" : "\n"
                    );
        }
        return $ret.sprintf('%s<input type="submit" value="%s"></form>%s',
                    $aID ? "<td>" : '',
                    $action,
                    $aID ? "</td>" : "\n"
                );
    }
    private function p($msg,$htmlType=null,$log=false) {
        $this->wos->p($msg,$htmlType,$log);
    }
    private function pDebug($msg,$text) {
        $this->wos->pDebug($msg,$text);
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
                // Put blank fields & '-' alliance at the bottom of the list
                if ($multiplier>0) {
                    if (empty($a[$key]) || empty($b[$key])) {
                        return -$ret;
                    }
                    if ($a[$key]=='-' || $b[$key]=='-') {
                        return -$ret;
                    }
                }
                return $ret;
            }
            // 2nd sort key: player_name ASC
            return strnatcmp(strtolower($a['player_name']), strtolower($b['player_name']));
        };
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
