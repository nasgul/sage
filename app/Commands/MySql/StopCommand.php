<?php

declare(strict_types = 1);

namespace App\Commands\MySql;

use App\Command;
use App\Facades\BrewService;

class StopCommand extends Command
{
    const COMMAND = 'mysql:stop';

    /**
     * @var string
     */
    protected $signature = self::COMMAND;

    /**
     * @var string
     */
    protected $description = 'Stop MySQL service';

    /**
     * @return void
     */
    public function handle(): void
    {
        $this->task('MySQL Stop', function () {
            try {
                BrewService::stop((string)config('env.mysql.formula'));
            } catch (\Exception $e) {
                return $e->getMessage();
            }
        });
    }
}
