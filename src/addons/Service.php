<?php
declare(strict_types=1);

namespace think\addons;

use think\Console;
use think\Route;
use think\facade\Config;
use think\facade\Lang;
use think\facade\Cache;
use think\facade\Event;
use think\addons\middleware\Addons;

class Service extends \think\Service
{
    /**
     * 插件路径
     * @var string
     */
    protected $addonsPath;

    /**
     * 注册服务
     */
    public function register()
    {
        // 绑定插件容器
        $this->app->bind('addons', Service::class);
        // 获取插件路径
        $this->addonsPath = $this->getAddonsPath();
        // 加载插件语言
        $this->loadLang();
        // 自动载入插件
        $this->autoload();
        // 加载插件事件
        $this->loadEvent();
        // 加载插件服务
        $this->loadService();
        // 挂载插件路由
        $this->loadRoutes();
        // 加载插件配置
        $this->loadApp();
    }

    /**
     * 安装服务
     */
    public function boot()
    {
        $this->registerRoutes(function (Route $route) {
            // 注册控制器路由
            $execute = '\\think\\addons\\Route::execute';
            $traffic = 'addons/:addon/[:module]/[:controller]/[:action]';
            $route->rule($traffic, $execute)->middleware(Addons::class);

            // 注册自定义路由
            $routes = (array) Config::get('addons.route', []);
            if (Config::get('addons.autoload', true)) {
                foreach ($routes as $key => $val) {
                    if (!$val) {
                        continue;
                    }
                    if (is_array($val)) {
                        if (isset($val['rule']) && isset($val['domain'])) {
                            $domain = $val['domain'];
                            $rules = [];
                            foreach ($val['rule'] as $k => $rule) {
                                $rule = rtrim($rule, '/');
                                [$addon, $module, $controller, $action] = explode('/', $rule);
                                $rules[$k] = [
                                    'addon'      => $addon,
                                    'module'     => $module,
                                    'controller' => $controller,
                                    'action'     => $action,
                                    'indomain'   => 1,
                                ];
                            }
                            if($domain){
                                if (!$rules) $rules = [
                                    '/' => [
                                        'addon'      => $val['addons'],
                                        'module'     => 'frontend',
                                        'controller' => 'index',
                                        'action'     => 'index',
                                    ]
                                ];
                                foreach (explode(',', $domain) as $item) {
                                    $route->domain($item, function () use ($rules, $route, $execute) {
                                        foreach ($rules as $k => $rule) {
                                            $k = explode('/', trim($k,'/'));
                                            $k = implode('/', $k);
                                            $route->rule($k, $execute)
                                                ->completeMatch(true)
                                                ->append($rule);
                                        }
                                    });
                                }
                            }else{
                                foreach ($rules as $k => $rule) {
                                    $k = '/' . trim($k,'/');
                                    $route->rule($k, $execute)
                                        ->completeMatch(true)
                                        ->append($rule);
                                }
                            }
                        }
                    } else {
                        $val = rtrim($val, '/');
                        list($addon, $module, $controller, $action) = explode('/', $val);
                        $route->rule($key, $execute)
                            ->completeMatch(true)
                            ->append([
                                'addon'      => $addon,
                                'module'     => $module,
                                'controller' => $controller,
                                'action'     => $action
                            ]);
                    }
                }
            }
        });
    }

    /**
     * 自动加载
     */
    private function autoload(): bool
    {
        // 钩子是否自动载入
        if (!Config::get('addons.autoload', true)) {
            return true;
        }

        // 插件钩子写入配置
        $config = Config::get('addons');
        $base = get_class_methods("\\think\\Addons");
        $base = array_merge($base, ['init', 'initialize', 'install', 'uninstall', 'enabled', 'disabled']);
        foreach (glob($this->getAddonsPath() . '*/*.php') as $addonsFile) {
            $info = pathinfo($addonsFile);
            $name = pathinfo($info['dirname'], PATHINFO_FILENAME);
            if (strtolower($info['filename']) === 'plugin') {
                $methods = (array)get_class_methods("\\addons\\" . $name . "\\" . $info['filename']);
                $hooks = array_diff($methods, $base);
                foreach ($hooks as $hook) {
                    if (!isset($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = [];
                    }
                    if (is_string($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = explode(',', $config['hooks'][$hook]);
                    }
                    if (!in_array($name, $config['hooks'][$hook])) {
                        $config['hooks'][$hook][] = $name;
                    }
                }
            }
        }

        Config::set($config, 'addons');
        return true;
    }

    /**
     * 加载语言
     */
    private function loadLang(): void
    {
        Lang::load([$this->app->getRootPath() . '/vendor/topwait/think-addons/src/lang/zh-cn.php']);
    }

    /**
     * 加载事件
     */
    private function loadEvent(): void
    {
        // 初始化钩子
        $hooks = $this->app->isDebug() ? [] : Cache::get('hooks', []);
        if (empty($hooks)) {
            $hooks = (array) Config::get('addons.hooks', []);
            foreach ($hooks as $key => $values) {
                if (is_string($values)) {
                    $values = explode(',', $values);
                } else {
                    $values = (array)$values;
                }
                $hooks[$key] = array_filter(array_map(function ($v) use ($key) {
                    return [get_addons_class($v), $key];
                }, $values));
            }
            Cache::set('hooks', $hooks);
        }

        // 直接执行钩子
        if (isset($hooks['AddonsInit'])) {
            foreach ($hooks['AddonsInit'] as $k => $v) {
                Event::trigger('AddonsInit', $v);
            }
        }

        // 监听钩子事件
        Event::listenEvents($hooks);
    }

    /**
     * 加载服务
     */
    private function loadService(): void
    {
        $results = scandir($this->addonsPath);
        $bind = [];
        foreach ($results as $name) {
            if (in_array($name, ['.', '..'])) {
                continue;
            }

            if (is_file($this->addonsPath . $name)) {
                continue;
            }

            $addonDir = $this->addonsPath . $name . DS;
            if (!is_dir($addonDir)) {
                continue;
            }

            if (!is_file($addonDir . ucfirst($name) . '.php')) {
                continue;
            }

            $serviceFile = $addonDir . 'service.ini';
            if (!is_file($serviceFile)) {
                continue;
            }

            $info = parse_ini_file($serviceFile, true, INI_SCANNER_TYPED) ?: [];
            $bind = array_merge($bind, $info);
        }
        $this->app->bind($bind);
    }

    /**
     * 加载路由
     */
    private function loadRoutes(): void
    {
        foreach (scandir($this->addonsPath) as $addonName) {
            if (in_array($addonName, ['.', '..'])) {
                continue;
            }

            if (!is_dir($this->addonsPath . $addonName)) {
                continue;
            }

            $moduleDir = $this->addonsPath . $addonName . DS;
            foreach (scandir($moduleDir) as $mdir) {
                if (in_array($mdir, ['.', '..'])) {
                    continue;
                }

                if(is_file($this->addonsPath . $addonName . DS . $mdir)) {
                    continue;
                }

                $addonsRouteDir = $this->addonsPath . $addonName . DS . $mdir . DS . 'route' . DS;
                if (file_exists($addonsRouteDir) && is_dir($addonsRouteDir)) {
                    $files = glob($addonsRouteDir . '*.php');
                    foreach ($files as $file) {
                        if (file_exists($file)) {
                            $this->loadRoutesFrom($file);;
                        }
                    }
                }
            }
        }
    }

    /**
     * 加载配置
     */
    private function loadApp()
    {
        $app    = app();
        $rules  = explode('/', $app->request->url());
        $rules  = array_splice($rules, 2, count($rules)-1);
        $addon  = $rules['addon']  ?? '';
        $module = $rules['module'] ?? '';

        // 加载插件应用级配置
        if (is_dir($this->addonsPath . $addon)) {
            foreach (scandir($this->addonsPath . $addon) as $name) {
                if (in_array($name, ['.', '..', 'public', 'view', 'middleware'])) {
                    continue;
                }

                $appConfigs = ['common.php', 'middleware.php', 'provider.php', 'event.php'];
                if (in_array($name, $appConfigs)) {
                    if (is_file($this->addonsPath . $addon . DS . 'common.php')) {
                        include_once $this->addonsPath . $addon . DS . 'common.php';
                    }

                    if (is_file($this->addonsPath . $addon . DS . 'middleware.php')) {
                        $app->middleware->import(include $this->addonsPath . $addon . DS . 'middleware.php', 'route');
                    }

                    if (is_file($this->addonsPath . $addon . DS . 'provider.php')) {
                        $app->bind(include $this->addonsPath . $addon . DS . 'provider.php');
                    }

                    if (is_file($this->addonsPath . $addon . DS . 'event.php')) {
                        $app->loadEvent(include $this->addonsPath . $addon . DS . 'event.php');
                    }

                    $commands = [];
                    $addonsConfigDir = $this->addonsPath . $addon . DS . 'config' . DS;
                    if (is_dir($addonsConfigDir)) {
                        $files = [];
                        $files = array_merge($files, glob($addonsConfigDir . '*' . $app->getConfigExt()));
                        if ($files) {
                            foreach ($files as $file) {
                                if (file_exists($file)) {
                                    if (substr($file, -11) == 'console.php') {
                                        $commandsConfig = include_once $file;
                                        isset($commandsConfig['commands']) && $commands = array_merge($commands, $commandsConfig['commands']);
                                        !empty($commands) && Console::starting(function (Console $console) use ($commands) {
                                            $console->addCommands($commands);
                                        });
                                    } else {
                                        $app->config->load($file, pathinfo($file, PATHINFO_FILENAME));
                                    }
                                }
                            }
                        }
                    }

                    $addonsLangDir = $this->addonsPath . $addon . DS . 'lang' . DS;
                    if (is_dir($addonsLangDir)) {
                        $files = glob($addonsLangDir . $app->lang->defaultLangSet() . '.php');
                        foreach ($files as $file) {
                            if (file_exists($file)) {
                                Lang::load([$file]);
                            }
                        }
                    }
                }
            }
        }

        // 加载插件模块级配置
        if (is_dir($this->addonsPath . $addon . DS . $module)) {
            foreach (scandir($this->addonsPath . $addon . DS . $module) as $modName) {
                if (in_array($modName, ['.', '..', 'public', 'view'])) {
                    continue;
                }

                $moduleConfigs = ['common.php', 'middleware.php', 'provider.php', 'event.php', 'config'];
                if (in_array($modName, $moduleConfigs)) {
                    if (is_file($this->addonsPath . $addon . DS . $module . DS . 'common.php')) {
                        include_once $this->addonsPath . $addon . DS . $module . DS . 'common.php';
                    }

                    if (is_file($this->addonsPath . $addon . DS . $module . DS . 'middleware.php')) {
                        $app->middleware->import(include $this->addonsPath . $addon . DS . $module . DS . 'middleware.php', 'route');
                    }

                    if (is_file($this->addonsPath . $addon . DS . $module . DS . 'provider.php')) {
                        $app->bind(include $this->addonsPath . $addon . DS . $module . DS . 'provider.php');
                    }

                    if (is_file($this->addonsPath . $addon . DS . $module . DS . 'event.php')) {
                        $app->loadEvent(include $this->addonsPath . $addon . DS . $module . DS . 'event.php');
                    }

                    $commands = [];
                    $moduleConfigDir = $this->addonsPath . $addon . DS . $module . DS . 'config' . DS;
                    if (is_dir($moduleConfigDir)) {
                        $files = [];
                        $files = array_merge($files, glob($moduleConfigDir . '*' . $app->getConfigExt()));
                        if($files){
                            foreach ($files as $file) {
                                if (file_exists($file)) {
                                    if (substr($file,-11) != 'console.php') {
                                        $app->config->load($file, pathinfo($file, PATHINFO_FILENAME));
                                    } else {
                                        $commandsConfig = include_once $file;
                                        isset($commandsConfig['commands']) && $commands = array_merge($commands, $commandsConfig['commands']);
                                        !empty($commands) && Console::starting(function (Console $console) use($commands) {$console->addCommands($commands);});
                                    }
                                }
                            }
                        }
                    }

                    $addonsLangDir = $this->addonsPath . $addon . DS . $module . DS . 'lang' . DS;
                    if (is_dir($addonsLangDir)) {
                        $files = glob($addonsLangDir . $app->lang->defaultLangSet() . '.php');
                        foreach ($files as $file) {
                            if (file_exists($file)) {
                                Lang::load([$file]);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * 获取插件路径
     */
    public function getAddonsPath(): string
    {
        $addonsPath = $this->app->getRootPath() . 'addons' . DS;
        if (!is_dir($addonsPath)) {
            @mkdir($addonsPath, 0755, true);
        }
        return $addonsPath;
    }

    /**
     * 获取插件配置
     */
    public function getAddonsConfig(): array
    {
        $name = $this->app->request->addon;
        $addon = get_addons_instance($name);
        if (!$addon) {
            return [];
        }
        return $addon->getConfig();
    }

}
