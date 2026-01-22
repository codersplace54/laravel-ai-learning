<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DeployBackend extends Command
{
    protected $signature = 'deploy:backend';
    protected $description = 'Deploy backend latest code';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $script = '/root/swaagat_backend_deployment_autmation/deployment.sh';

        if (!file_exists($script)) {
            $this->error('Deployment script not found');
            return Command::FAILURE;
        }

        $output = shell_exec("bash $script 2>&1");
        $this->info($output);

        return Command::SUCCESS;
    }
}
