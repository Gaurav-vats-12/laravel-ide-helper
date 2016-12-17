<?php
/**
 * Laravel IDE Helper Generator
 *
 * @author    Barry vd. Heuvel <barryvdh@gmail.com>
 * @copyright 2014 Barry vd. Heuvel / Fruitcake Studio (http://www.fruitcakestudio.nl)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      https://github.com/barryvdh/laravel-ide-helper
 */

namespace Barryvdh\LaravelIdeHelper;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Config\Repository as ConfigRepository;
use ReflectionClass;
use Symfony\Component\Console\Output\OutputInterface;

class Generator
{
    /** @var \Illuminate\Config\Repository */
    protected $config;

    /** @var \Illuminate\View\Factory */
    protected $view;

    /** @var \Symfony\Component\Console\Output\OutputInterface */
    protected $output;

    protected $extra = array();
    protected $magic = array();
    protected $interfaces = array();
    protected $helpers;

    /**
     * @param \Illuminate\Config\Repository $config
     * @param \Illuminate\View\Factory $view
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string $helpers
     */
    public function __construct(
        /*ConfigRepository */ $config,
        /* Illuminate\View\Factory */ $view,
        OutputInterface $output = null,
        $helpers = ''
    ) {
        $this->config = $config;
        $this->view = $view;

        // Find the drivers to add to the extra/interfaces
        $this->detectDrivers();

        $this->extra = array_merge($this->extra, $this->config->get('ide-helper.extra'));
        $this->magic = array_merge($this->magic, $this->config->get('ide-helper.magic'));
        $this->interfaces = array_merge($this->interfaces, $this->config->get('ide-helper.interfaces'));
        // Make all interface classes absolute
        foreach ($this->interfaces as &$interface) {
            $interface = '\\' . ltrim($interface, '\\');
        }
        $this->helpers = $helpers;
    }

    /**
     * Generate the helper file contents;
     *
     * @param  string  $format  The format to generate the helper in (php/json)
     * @return string;
     */
    public function generate($format = 'php')
    {
        // Check if the generator for this format exists
        $method = 'generate'.ucfirst($format).'Helper';
        if (method_exists($this, $method)) {
            return $this->$method();
        }

        return $this->generatePhpHelper();
    }

    public function generatePhpHelper()
    {
        $app = app();
        return $this->view->make('ide-helper::helper')
            ->with('namespaces', $this->getNamespaces())
            ->with('helpers', $this->helpers)
            ->with('version', $app->version())
            ->with('include_fluent', $this->config->get('ide-helper.include_fluent', false))
            ->render();
    }

    public function generateJsonHelper()
    {
        $classes = array();
        foreach ($this->getNamespaces() as $aliases) {
            foreach ($aliases as $alias) {
                $functions = array();
                foreach ($alias->getMethods() as $method) {
                    $functions[$method->getName()] = '('. $method->getParamsWithDefault().')';
                }
                $classes[$alias->getAlias()] = array(
                    'functions' => $functions,
                );
            }
        }

        $flags = JSON_FORCE_OBJECT;
        if (defined('JSON_PRETTY_PRINT')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode(array(
            'php' => array(
                'classes' => $classes,
            ),
        ), $flags);
    }

    /**
     * The auth settings are defined in config/auth.php
     *
     * \Illuminate\Auth\AuthManager already added by default
     * @see \Illuminate\Auth\AuthServiceProvider::registerAuthenticator()
     * @see \Illuminate\Auth\AuthManager
     *
     * \Illuminate\Auth\RequestGuard is not a supported guard driver.
     * @see \Illuminate\Auth\AuthManager::viaRequest()
     * @see \Illuminate\Auth\RequestGuard
     *
     * Supported: "session", "token"
     * @see \Illuminate\Auth\AuthManager::resolve()
     * @see \Illuminate\Auth\AuthManager::createSessionDriver()
     * @see \Illuminate\Auth\AuthManager::createTokenDriver()
     * @see \Illuminate\Auth\SessionGuard
     * @see \Illuminate\Auth\TokenGuard
     *
     * @see https://laravel.com/docs/5.2/authentication
     * @see https://laravel.com/api/5.2/Illuminate/Auth.html
     * @see \Illuminate\Auth\AuthServiceProvider
     * @see \Illuminate\Auth\AuthManager::__call
     * @see \Illuminate\Contracts\Auth\Guard
     * @see \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function detectDrivers()
    {
        try {
            if (config('auth.providers.users.model', null) !== null) {
                $defaultUserModels = [];
                $interface = 'Illuminate\Contracts\Auth\Authenticatable';
                foreach (array_pluck(config('auth.providers', []), 'model') as $userModel) {
                    if (class_exists($userModel) && in_array($interface, class_implements($userModel))) {
                        $defaultUserModels[] = $userModel;
                    }
                }
                if (count($defaultUserModels) > 0) {
                    $this->interfaces['\\' . $interface] = implode('|', $defaultUserModels) . '|\\' . $interface;
                }
                unset($defaultUserModels, $interface);
            } else {
                $defaultUserModel = config('auth.model', 'App\User');
                $interface = 'Illuminate\Contracts\Auth\Authenticatable';
                if (class_exists($defaultUserModel) && in_array($interface, class_implements($defaultUserModel))) {
                    $this->interfaces['\\' . $interface] = $defaultUserModel . '|\\' . $interface;
                }
                unset($defaultUserModel, $interface);
            }
        } catch (\Exception $e) {
        }

        try {
            if (class_exists('Auth') && is_a('Auth', '\Illuminate\Support\Facades\Auth', true)) {
                if (class_exists('\Illuminate\Foundation\Application')) {
                    $authMethod = version_compare(Application::VERSION, '5.2', '>=') ? 'guard' : 'driver';
                } else {
                    $refClass = new ReflectionClass('\Laravel\Lumen\Application');
                    $versionStr = $refClass->newInstanceWithoutConstructor()->version();
                    $authMethod = strpos($versionStr, 'Lumen (5.0') === 0 ?
                        'driver' :
                        (strpos($versionStr, 'Lumen (5.1') === 0 ? 'driver' : 'guard');
                }

                // For one guard and with default
                if (count(config('auth.guards', [])) <= 1 && config('auth.guard.driver', null) !== null) {
                    $class = get_class(\Auth::$authMethod());
                    $this->extra['Auth'] = array($class);
                    $this->interfaces['\Illuminate\Auth\UserProviderInterface'] = $class;
                } else { // For multiple guards without default
                    $drivers = array_values(array_unique(array_pluck(config('auth.guards', []), 'driver')));

                    foreach ($drivers as $driver) {
                        $class = 'Illuminate\\Auth\\'.studly_case($driver).'Guard';
                        if (class_exists($class)) {
                            if (!isset($this->extra['Auth'])) {
                                $this->extra['Auth'] = [];
                            }
                            $this->extra['Auth'][] = $class;
                        }
                    }

                    switch (count($drivers)) {
                        case 0:
                            $interfaces = ['Illuminate\Contracts\Auth\Guard'];
                            break;
                        case 1:
                            // The class is enough
                            $interfaces = [];
                            break;
                        default:
                            // It could use a reflection to get the implement, but for now there are only two possible
                            // options.
                            // The order is important for the following loop.
                            $interfaces = [
                                'Illuminate\Contracts\Auth\Guard',
                                'Illuminate\Contracts\Auth\StatefulGuard'
                            ];
                    }

                    if (count($interfaces) > 0 && !isset($this->magic['Auth'])) {
                        $this->magic['Auth'] = [];
                    }
                    foreach ($interfaces as $interface) {
                        foreach (get_class_methods($interface) as $method) {
                            if (!isset($this->magic['Auth'][$method])) {
                                $this->magic['Auth'][$method] = $interface.'::'.$method;
                            }
                        }
                    }
                }

                unset($class, $drivers, $interfaces);
            }
        } catch (\Exception $e) {
        }

        try {
            if (class_exists('DB') && is_a('DB', '\Illuminate\Support\Facades\DB', true)) {
                $class = get_class(\DB::connection());
                $this->extra['DB'] = array($class);
                $this->interfaces['\Illuminate\Database\ConnectionInterface'] = $class;
            }
        } catch (\Exception $e) {
        }

        try {
            if (class_exists('Cache') && is_a('Cache', '\Illuminate\Support\Facades\Cache', true)) {
                $driver = get_class(\Cache::driver());
                $store = get_class(\Cache::getStore());
                $this->extra['Cache'] = array($driver, $store);
                $this->interfaces['\Illuminate\Cache\StoreInterface'] = $store;
            }
        } catch (\Exception $e) {
        }

        try {
            if (class_exists('Queue') && is_a('Queue', '\Illuminate\Support\Facades\Queue', true)) {
                $class = get_class(\Queue::connection());
                $this->extra['Queue'] = array($class);
                $this->interfaces['\Illuminate\Queue\QueueInterface'] = $class;
            }
        } catch (\Exception $e) {
        }

        try {
            if (class_exists('SSH') && is_a('SSH', '\Illuminate\Support\Facades\SSH', true)) {
                $class = get_class(\SSH::connection());
                $this->extra['SSH'] = array($class);
                $this->interfaces['\Illuminate\Remote\ConnectionInterface'] = $class;
            }
        } catch (\Exception $e) {
        }

        try {
            if (class_exists('Storage') && is_a('Storage', '\Illuminate\Support\Facades\Storage', true)) {
                $class = get_class(\Storage::disk());
                $this->extra['Storage'] = array($class);
                $this->interfaces['\Illuminate\Contracts\Filesystem\Filesystem'] = $class;
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * Find all namespaces/aliases that are valid for us to render
     *
     * @return array
     */
    protected function getNamespaces()
    {
        $namespaces = array();

        // Get all aliases
        foreach ($this->getAliases() as $name => $facade) {
            // Skip the Redis facade, if not available (otherwise Fatal PHP Error)
            if ($facade == 'Illuminate\Support\Facades\Redis' && !class_exists('Predis\Client')) {
                continue;
            }

            $magicMethods = array_key_exists($name, $this->magic) ? $this->magic[$name] : array();
            $alias = new Alias($name, $facade, $magicMethods, $this->interfaces);
            if ($alias->isValid()) {
                //Add extra methods, from other classes (magic static calls)
                if (array_key_exists($name, $this->extra)) {
                    $alias->addClass($this->extra[$name]);
                }

                $namespace = $alias->getNamespace();
                if (!isset($namespaces[$namespace])) {
                    $namespaces[$namespace] = array();
                }
                $namespaces[$namespace][] = $alias;
            }
        }

        return $namespaces;
    }

    protected function getAliases()
    {
        // For Laravel, use the AliasLoader
        if (class_exists('Illuminate\Foundation\AliasLoader')) {
            return AliasLoader::getInstance()->getAliases();
        }

        $facades = [
          'App' => 'Illuminate\Support\Facades\App',
          'Auth' => 'Illuminate\Support\Facades\Auth',
          'Bus' => 'Illuminate\Support\Facades\Bus',
          'DB' => 'Illuminate\Support\Facades\DB',
          'Cache' => 'Illuminate\Support\Facades\Cache',
          'Cookie' => 'Illuminate\Support\Facades\Cookie',
          'Crypt' => 'Illuminate\Support\Facades\Crypt',
          'Event' => 'Illuminate\Support\Facades\Event',
          'Hash' => 'Illuminate\Support\Facades\Hash',
          'Log' => 'Illuminate\Support\Facades\Log',
          'Mail' => 'Illuminate\Support\Facades\Mail',
          'Queue' => 'Illuminate\Support\Facades\Queue',
          'Request' => 'Illuminate\Support\Facades\Request',
          'Schema' => 'Illuminate\Support\Facades\Schema',
          'Session' => 'Illuminate\Support\Facades\Session',
          'Storage' => 'Illuminate\Support\Facades\Storage',
          //'Validator' => 'Illuminate\Support\Facades\Validator',
        ];

        $facades = array_merge($facades, $this->config->get('app.aliases', []));

        // Only return the ones that actually exist
        return array_filter($facades, function ($alias) {
            return class_exists($alias);
        });
    }

    /**
     * Get the driver/connection/store from the managers
     *
     * @param $alias
     * @return array|bool|string
     */
    public function getDriver($alias)
    {
        try {
            if ($alias == "Auth") {
                $driver = \Auth::driver();
            } elseif ($alias == "DB") {
                $driver = \DB::connection();
            } elseif ($alias == "Cache") {
                $driver = get_class(\Cache::driver());
                $store = get_class(\Cache::getStore());
                return array($driver, $store);
            } elseif ($alias == "Queue") {
                $driver = \Queue::connection();
            } else {
                return false;
            }

            return get_class($driver);
        } catch (\Exception $e) {
            $this->error("Could not determine driver/connection for $alias.");
            return false;
        }
    }

    /**
     * Write a string as error output.
     *
     * @param  string  $string
     * @return void
     */
    protected function error($string)
    {
        if ($this->output) {
            $this->output->writeln("<error>$string</error>");
        } else {
            echo $string . "\r\n";
        }
    }
}
