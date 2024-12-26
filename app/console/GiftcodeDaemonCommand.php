<?php

namespace App\Console;

use Aloe\Command;
use App\Helpers\WosCommon;
use App\Models\GiftcodeStatistics;
use Leaf\Config;
use Leaf\Exceptions\ErrorException;
use Exception;
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
    const PID_FILENAME = '/var/www/wos245/giftcoded.pid';

    protected static $defaultName = 'giftcode:daemon';
    public $description = 'Daemon to send gift codes in the background.';
    public $help = "To run:\n  php leaf giftcode:daemon [username]\n\nDefault username=docker\n";

    private $wos;               // WosCommon object

    // Signals the daemon should handle
	public static $signalsToHandle = [ SIGQUIT, SIGINT, SIGTERM, SIGABRT, SIGHUP ];
	public static $signalReceived  = NULL;

    /**
     * Configure your command
     */
    protected function config()
    {
        // docker = same username apache2 runs under in this container
        $this->setArgument('username','optional','Unix username to run under','docker');
    }

    /**
     * Main body for your command
     */
    protected function handle()
    {
        $this->wos = new WosCommon();
		$this->wos->myPID = posix_getpid();
        $this->comment("=== Starting up Giftcode daemon PID=".$this->wos->myPID);

// While developing, force full debug mode
$this->wos->dbg = true;
$this->wos->guzEmulate = true;

        // Run under the correct username and set up PID file, BEFORE writing to log file
        $this->switchUser($this->argument('username'));
        $this->writePIDFile();

        // Cleared to run, init other systems with "default" alliance
        $alliance = array_keys($this->wos->host2Alliance)[0];
        $this->wos->setAllianceState($alliance);
        $this->info('Default alliance='.$this->wos->ourAlliance.' state #'.$this->wos->ourState.' dataDir='.$this->wos->dataDir.
            "\n".__CLASS__.': dbg='.($this->wos->dbg?1:0).' guzEmulate='.($this->wos->guzEmulate?1:0));

		// Install signal handler method for graceful termination
        pcntl_async_signals(true);  // Enable asynchronous signal handling
		foreach ( static::$signalsToHandle as $signal) {
			pcntl_signal($signal, __CLASS__.'::signalHandler');
		}

		// Start work
        $this->p("=== giftcoded daemon started");
        if ( $this->wos->dbg ) {
            $this->p(__CLASS__.': dbg='.($this->wos->dbg?1:0).' guzEmulate='.($this->wos->guzEmulate?1:0));
        }
        $this->daemonLoop();

        // Clean up and exit
        $this->p("=== giftcoded EXIT");
		$this->pExit(null,0);
    }

    private function daemonLoop() {
        while ( empty(static::$signalReceived) ) {
            foreach ($this->wos->alliance2Long as $alliance => $allianceLong) {
                // Handle received signal
                if ( static::$signalReceived ) {
                    $this->p("*** exiting main loop, received signal ".static::$signalReceived);
                    return;
                }

                // Switch alliance and process
                #$allianceShort = strtolower($alliance);
                $this->wos->setAllianceState($alliance);

                try {
                    // If we happen to have multiple giftcodes to process, FIFO
                    $allGiftCodes = db()
                        ->select('giftcodes')
                        ->where('send_gift_ts', -1)
                        ->orderBy('id','asc')
                        ->limit(1)
                        ->all();
                    if ( !empty($allGiftCodes) ) {
                        $this->sendGift($allGiftCodes[0]);
                    }
                } catch (PDOException $ex) {
                    $this->p('DB ERROR looking for giftcode: '.$ex->getMessage());
                    return;
                }
            }
            $this->p('Sleeping 10...');
            sleep(10);
            return;
        }
    }

    /**
     * Send reward code to all users.
     */
    private function sendGift($gc) {
        $giftCode = $gc['code'];
        $this->p("--- Sending giftcode=$giftCode alliance=".$this->wos->ourAlliance);
        $startTime = $this->wos->getTimestring(true,true);
        $this->wos->stats = new GiftcodeStatistics();
        $this->wos->stats->parseJsonStatistics($gc['statistics']);
        if ($this->wos->dbg) {
            $this->pDebug('prevStats for this gift code',$gc);
        }

        return;

        // Update giftcode record to say we started
        if ( $this->wos->updateGiftcodeStats($giftCode, $startTime) <1 ) {
            $this->pExit('Could not update giftcodes table',500);
        }

        $httpReturnCode = 200;
        $errMsg = [];
        $n = 0; // # of players attempted
        $xrlrPauseTime = 61; // sleep time when reaching x-ratelimit-remaining
        $this->wos->badResponsesLeft = 5; // Max bad responses (network error) from API before abort
        try {
            $allPlayers = $this->wos->getPlayersForGiftcode($giftCode,false);
            $numPlayers = count($allPlayers);
            $label = 'Try#'.count($this->wos->stats->expected)+1;
            $this->wos->stats->expected[$label] = $numPlayers;
            if ( $numPlayers == 0 ) {
                $errMsg[] = 'No players in the database that still need that gift code.';
                $httpReturnCode = 404;
            } else if ($this->wos->dbg) {
                $this->p(__METHOD__." Found $numPlayers to process",'p',true);
            }
            foreach ($allPlayers as $p) {
                if ( $this->wos->badResponsesLeft<1 || !empty(static::$signalReceived) ) {
                    break;
                }
                $n++;
                if ($this->wos->guzEmulate && $n>10) break; // Debug use

                usleep(100000); // 100msec slow-down between players
                $signInResponse = $this->wos->verifyPlayerInWOS($p);
                if ( is_null($signInResponse) || !empty(static::$signalReceived) ) {
                    // Do not continue process, retryable API problem
                    break;
                } else if ( ! $signInResponse['playerGood'] ) {
                    // Invalid sign-in, ignore this player
                    $this->wos->updateGiftcodeStats($giftCode);
                    continue;
                }

                // API ratelimit: assume if it hits 0 we have to wait 1 minute
                $xrlr = $signInResponse['headers']['x-ratelimit-remaining'];
                if ($xrlr < 2 && $n < $numPlayers) {
                    $this->p("(signIn x-ratelimit-remaining=$xrlr - pause $xrlrPauseTime sec) ");
                    // Proactively sleep here
                    if ( ! $this->wos->guzEmulate ) {
                        sleep($xrlrPauseTime);
                        if ( !empty(static::$signalReceived) ) {
                            break;  // sleep interrupted, quit now
                        }
                    }
                }

                // Clear to send gift code!
                $giftResponse = $this->wos->send1Giftcode($p['id'],$giftCode);
                if ( is_null($giftResponse)  || !empty(static::$signalReceived) ) {
                    break;  // Do not continue process, API problem
                }

                // API ratelimit: if it hits 0 we have to wait 1 minute
                if ( is_array($giftResponse) ) {
                    $xrlr = $giftResponse['headers']['x-ratelimit-remaining'];
                    if ($xrlr < 2 && $n < $numPlayers) {
                        $this->wos->stats->hitRateLimit++;
                        $this->p("(gift x-ratelimit-remaining=$xrlr - pause $xrlrPauseTime sec) ",0,true);
                        // Proactively sleep here
                        if ( ! $this->wos->guzEmulate && $n < $numPlayers) {
                            sleep($xrlrPauseTime);
                        }
                    }
                }
            }
        } catch (PDOException $ex) {
            $errMsg[] = __METHOD__.' <b>DB ERROR:</b> '.$ex->getMessage();
            $httpReturnCode = 500;
        } catch (Exception $ex) {
            $errMsg[] = __METHOD__.' <b>Exception:</b> '.$ex->getMessage();
            if ($this->wos->dbg) {
                $this->pDebug('exception=',$ex);
            }
            $httpReturnCode = 500;
        }
        if ( $this->wos->badResponsesLeft<1 ) {
            $errMsg[] = 'exceeded max bad responses.';
            $httpReturnCode = 500;
        }
        $this->p("Processed $n players");
        $this->wos->stats->runtime += $this->wos->getTimestring(true,true) - $startTime;
        $this->wos->updateGiftcodeStats($giftCode,0);
        if ( $httpReturnCode>200 ) {
            if ( $httpReturnCode>404 ) {
                $errMsg[] = 'Incomplete run!';
            }
            $this->pExit($errMsg,$httpReturnCode);
        }
        $this->p("### SUCCESS giftcode=$giftCode alliance=".$this->wos->ourAlliance);
    }

    ///////////////////////// Daemon helper functions
    private function writePIDFile() {
		// Ensure no currently running daemon
		if ( file_exists(self::PID_FILENAME) ) {
			$oldPID = intval(file_get_contents(self::PID_FILENAME));

			// Clean up if previous daemon didn't clean up after itself
			// and handle EPERM error
			//   http://www.php.net/manual/en/function.posix-kill.php#82560
			if ( $oldPID > 0 ) {
				// This signal of 0 only checks whether the old PID is running
				$bRunning = posix_kill($oldPID, 0);
				if ( posix_get_last_error()==1 ) {
					$bRunning = true;
				}
				// ONLY if confirmed not running should we remove the file
				if ( !$bRunning ) {
					unlink(self::PID_FILENAME);
				}
			}
		}
		if ( file_exists(self::PID_FILENAME) ) {
			$this->error("Daemon pid=$oldPID still running according to PID file ".self::PID_FILENAME);
            exit(403);
		}

        // Create PID file
		if ( ! file_put_contents(self::PID_FILENAME, $this->wos->myPID) ) {
			$this->error('Cannot write PID file '.self::PID_FILENAME);
            exit(500);
		} else if ( $this->wos->dbg ) {
		    $this->info('Successfully created PID file '.self::PID_FILENAME);
        }
	}
	private function switchUser($username) {
        $pwname = posix_getpwnam($username);
        if ($pwname==false) {
            $this->error("Unix username '$username' does not exist");
            exit(500);
        }
        $targetUID = $pwname['uid'];
        if ( $this->wos->dbg ) {
            $this->info('pre UID='.posix_getuid().' EUID='.posix_geteuid());
        }
		if ( posix_geteuid()!=$targetUID && ! posix_setuid($targetUID) ) {
			// Couldn't change UID, so clean up!
            $this->error("Cannot change to UID=$targetUID '$username'");
            exit(500);
		}
	}
    // Registered signal handler function - has to be static
	public static function signalHandler($signo) {
		// Using a global variable for the instantiated object, and application
		// to have easy access to the signal received.
        $myPID = posix_getpid();
        if ( $signo == SIGHUP ) {
            print ">>> PID=$myPID ignoring signal $signo\n";
        } else if ( array_search($signo, static::$signalsToHandle) !== false ) {
            print "### Daemon PID=$myPID received signal #".static::$signalReceived."\n";
            static::$signalReceived = $signo;
        }
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
                $this->error($l);
            }
        }
        if ( file_exists(self::PID_FILENAME) ) {
            unlink(self::PID_FILENAME);
        }
        exit($httpReturnCode);
    }
}
