<?php

namespace App\Console;

use Aloe\Command;
use App\Helpers\WosCommon;
use App\Models\GiftcodeStatistics;
use Leaf\Config;
use PDOException;

/*
 * Settings required for daemon to run forever, and proper handling of signals
 * sent to it.
 */
ini_set("max_execution_time", 0);
ini_set("max_input_time", 0);
set_time_limit(0);

class GiftcodeDaemonCommand extends Command
{
    protected static $defaultName = 'giftcode:daemon';
    public $description = 'Daemon to send gift codes in the background.';
    public $help = "To run:\n  php leaf giftcode:daemon\n";

    private $wos;               // WosCommon object
    private $stats;             // GiftcodeStatistics object
    private $badResponsesLeft;  // Number of questionable bad responses from WOS API before abort

    // Signals the daemon should handle
	public static $signalsToHandle = [ SIGQUIT, SIGTERM, SIGABRT, SIGHUP ];
	public static $signalReceived  = NULL;

    /**
     * Configure your command
     */
    protected function config()
    {
        return;
        // you can add arguments and options in the config method
        $this
            ->setArgument('argument', 'required', 'argument description')
            ->setOption('option', 'o', 'required', 'option description');
    }

    /**
     * Main body for your command
     */
    protected function handle()
    {
        $this->comment("=== Starting up daemon");
        $this->wos = new WosCommon();

        // While developing this...
        $this->wos->dbg = true;
        $this->wos->guzEmulate = true;

        $alliance = array_keys($this->wos->host2Alliance)[0];
        $this->wos->setAllianceState($alliance);
        $this->info('=== Default alliance='.$this->wos->ourAlliance.' state #'.$this->wos->ourState.' dataDir='.$this->wos->dataDir.
            "\n".__CLASS__.': dbg='.($this->wos->dbg?1:0).' guzEmulate='.($this->wos->guzEmulate?1:0));

        $this->p('Daemon starting');

        db()->update('giftcodes')->params(['send_gift_ts'=>-1])->where(['id'=>3])->execute();

        #$this->comment("Daemon shut down");
        #return 0;

		// Install signal handler method
        pcntl_async_signals(true);  // Enable asynchronous signal handling
		foreach ( static::$signalsToHandle as $signal) {
			pcntl_signal($signal, __CLASS__.'::signalHandler');
		}

        $this->daemonLoop();
        ########################
        #db()->update('giftcodes')->params(['send_gift_ts'=>-1])->where(['id'=>3])->execute();


        usleep(500);
        $this->comment("=== Daemon shut down");
        return 0;
    }

    private function daemonLoop() {
        while (1) {
            foreach ($this->wos->alliance2Long as $alliance => $allianceLong) {
                // Handle received signal
                if ( static::$signalReceived ) {
                    $this->p("***** exiting main loop, received signal ".static::$signalReceived);
                    return;
                }
                $allianceShort = strtolower($alliance);
                $this->wos->setAllianceState($alliance);

                $allCodes = db()
                    ->select('giftcodes')
                    ->where('send_gift_ts', -1)
                    ->orderBy('id','asc')
                    ->limit(1)
                    ->all();
                $this->pDebug("[$alliance]$allianceLong where ts=-1",$allCodes);
            }
            sleep(5);
        }
    }

    /**
     * Send reward code to all users.
     */
    private function sendGift($giftCode) {
        $this->p($this->wos->ourAlliance." sending $giftCode to all players that haven't yet received it:");

        // Create initial stub record for this giftcode
        $this->stats = new GiftcodeStatistics();
        $startTime = $this->wos->getTimestring(true,true);
        try {
            $gc = db()->select('giftcodes')
                ->where(['code' => $giftCode])
                ->first();
            $giftCodeID = 0;
            if ( !empty($gc) ) {
                $this->stats->parseJsonStatistics($gc['statistics']);
                $giftCodeID = $gc['id'];
            }
            if ($this->wos->dbg) {
                $this->pDebug('prevStats for this gift code',$gc);
            }
            $this->stats->increment('usersSending',$_SERVER['REMOTE_USER']);
            $s = $this->stats->getJson();
            $t = $this->wos->getTimestring(false,false);
            if ( $giftCodeID ) {
                $rowsUpdated = db()
                    ->update('giftcodes')
                    ->params(['updated_at' => $t,
                              'statistics' => $s
                            ])
                    ->where(['id' => $giftCodeID])
                    ->execute()
                    ->rowCount();
                if ( $rowsUpdated<1 ) {
                    $this->pExit('Could not update giftcodes table',500);
                }
            } else {
                db()
                    ->insert('giftcodes')
                    ->params(['code'        => $giftCode,
                              'created_at'  => $t,
                              'updated_at'  => $t,
                              'statistics'  => $s
                            ])
                    ->execute();
                $giftCodeID = db()->lastInsertId();
            }
            /*  INSERT or UPDATE will increment ID on UPDATE, so don't use it!
             *
            db()->query('INSERT INTO giftcodes(code,created_at,updated_at,statistics) '.
                        'VALUES (?,?,?,?) ON CONFLICT(code) '.
                        'DO UPDATE SET updated_at=?, statistics=?')
                ->bind($giftCode, $t, $t, $s, $t, $s)
                ->execute();
            */
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
            if ($this->wos->dbg) {
                $this->p(__METHOD__." Found $numPlayers to process",'p',true);
            }
            if ( $numPlayers == 0 ) {
                $errMsg[] = 'No players in the database that still need that gift code.';
                $httpReturnCode = 404;
            }
            if ($this->wos->dbg) {
                $this->p("numPlayers=$numPlayers",'p',true);
            }
            foreach ($allPlayers as $p) {
                usleep(100000); // 100msec slow-down between players
                if ( $this->badResponsesLeft < 1 ) {
                    break;
                }
                $n++;

                // Debug use
                if ($this->wos->guzEmulate && $n>20) break;

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
                    if ( ! $this->wos->guzEmulate ) {
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
                    if ( ! $this->wos->guzEmulate && $n < $numPlayers) {
                        sleep($xrlrPauseTime);
                    }
                }
            }
        } catch (PDOException $ex) {
            $errMsg[] = __METHOD__.' <b>DB ERROR:</b> '.$ex->getMessage();
            $httpReturnCode = 500;
        } catch (\Exception $ex) {
            $errMsg[] = __METHOD__.' <b>Exception:</b> '.$ex->getMessage();
            if ($this->wos->dbg) {
                $this->pDebug('exception=',$ex);
            }
            $httpReturnCode = 500;
        }
        if ( $this->badResponsesLeft<1 ) {
            $errMsg[] = 'exceeded max bad responses.';
            $httpReturnCode = 500;
        }
        $this->p("Processed $n players",'p',true);
        $this->stats->runtime = $this->stats->runtime + ($this->wos->getTimestring(true,true) - $startTime);
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

    ///////////////////////// Output/log functions
    private function p($msg,$ignore1=null,$ignore2=null) {
        $this->wos->p($msg,null,true);
    }
    private function pDebug($msg,$text) {
        $this->wos->pDebug($msg,$text);
    }
    private function pExit($msg,$httpReturnCode) {
        if ( !empty($msg) ) {
            $lines = is_array($msg) ? $msg : [$msg];
            foreach ($lines as $l ) {
                $this->p('>>>> ABORT: '.$l);
            }
        }
        $this->comment("=== Daemon shut down");
        exit($httpReturnCode);
    }

    /////////////////////////
    /**
	 * Registered signal handler function - has to be static
	 *
	 * @param int $signo
	 */
	public static function signalHandler($signo) {
		// Using a global variable for the instantiated object, and application
		// to have easy access to the signal received.
        if ( $signo == SIGHUP ) {
            print ">>> ignoring signal $signo\n";
        } else if ( array_search($signo, static::$signalsToHandle) !== false ) {
            static::$signalReceived = $signo;
            print "### Daemon received signal #".static::$signalReceived."\n";
        }
	}
}
