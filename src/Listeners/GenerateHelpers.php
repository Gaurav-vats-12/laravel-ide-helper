<?php

namespace Barryvdh\LaravelIdeHelper\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Console\Kernel as Artisan;

class GenerateHelpers
{
    protected $artisan;
    protected $config;

    public function __construct(Artisan $artisan, Config $config)
    {
        $this->artisan = $artisan;
        $this->config = $config;
    }

    /**
     * Handle the event.
     *
     * @param  CommandFinished  $event
     * @return void
     */
    public function handle(CommandFinished $event)
    {
        switch ($event->command) {
            case "package:discover":
                $this->artisan->call(
                    'ide-helper:generate',
                    $this->config->get('ide-helper.listen_generate_parameters', []),
                    $event->output
                );
                break;
            case "migrate":
            case "migrate:rollback":
                $this->artisan->call(
                    'ide-helper:models',
                    $this->config->get('ide-helper.listen_model_parameters', []),
                    $event->output
                );
                break;
        }
    }
}
