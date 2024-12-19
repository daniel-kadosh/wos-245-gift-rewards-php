<?php

namespace App\Console;

use Aloe\Command;
use Spatie\Async\Pool;

class GiftcodeDaemonMultiChildCommand extends Command
{
    protected static $defaultName = 'giftcode:daemon';
    public $description = 'Daemon to send gift codes in the background';
    public $help = 'giftcode:daemon command\'s help';

    private $logfile = '/var/www/wos245/giftdaemon.log';

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
        $this->comment( "Hello world  cwd=".getcwd());

        $pool = Pool::create();

        $pool->add([$this,'sendGift'])
            ->then(function ($output) {
                // Handle success
            });
        $pool->add([$this,'sendGift2'])
        ->then(function ($output) {
            // Handle success
        });


        $this->p('Parent starting children '.posix_getpid());
        $pool->wait();
        $this->p('=== Parent END');

        return 0;
    }

    public function sendGift() {
        $this->p(__METHOD__.' Start '.posix_getpid());
        for ($i=1000; $i<1010; $i++) {
            $this->p("== $i");
            sleep(1);
        }
        $this->p(__METHOD__.' Ended');
    }
    public function sendGift2() {
        $this->p(__METHOD__.' START '.posix_getpid());
        for ($i=0; $i<20; $i++) {
            $this->p($i);
            sleep(1);
        }
        $this->p(__METHOD__.' END');
    }

    private function p($message) {
        file_put_contents($this->logfile,$message."\n",LOCK_EX | FILE_APPEND);
    }
}
