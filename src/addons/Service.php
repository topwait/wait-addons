<?php
// +----------------------------------------------------------------------
// | 基于ThinkPHP6的插件化模块 [WaitAdmin专属订造]
// +----------------------------------------------------------------------
// | github: https://github.com/topwait/wait-addons
// | Author: zero <2474369941@qq.com>
// +----------------------------------------------------------------------
declare(strict_types=1);

namespace wait\addons;

use Exception;
use FilesystemIterator;
use think\Console;
use think\Route;
use think\facade\Config;
use think\facade\Lang;
use think\facade\Cache;
use think\facade\Event;
use wait\addons\middleware\Addons;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use UnexpectedValueException;

class Service extends \think\Service
{
    /**
     * 插件路径
     * @var string
     */
    protected string $addonsPath;

    /**
     * 所有插件init
     * @var array
     */
    protected array $addonsIniArray = [];

    /**
     * 注册服务
     */
    public function register(): void
    {
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
        // 绑定插件容器
        $this->app->bind('addons', Service::class);
    }

    /**
     * 安装服务
     */
    public function boot(): void
    {
        $this->registerRoutes(function (Route $route) {
            // 注册控制器路由
            $execute = '\\wait\\addons\\Route::execute';
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
                                                ->completeMatch()
                                                ->append($rule);
                                        }
                                    });
                                }
                            }else{
                                foreach ($rules as $k => $rule) {
                                    $k = '/' . trim($k,'/');
                                    $route->rule($k, $execute)
                                        ->completeMatch()
                                        ->append($rule);
                                }
                            }
                        }
                    } else {
                        $val = rtrim($val, '/');
                        list($addon, $module, $controller, $action) = explode('/', $val);
                        $route->rule($key, $execute)
                            ->completeMatch()
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
    private function autoload(): void
    {
        // 钩子是否自动载入
        if (!Config::get('addons.autoload', true)) {
            return;
        }

        // 插件钩子写入配置
        $config = Config::get('addons');
        $base = get_class_methods('\\wait\\Addons');
        $base = array_merge($base, ['init', 'initialize', 'install', 'uninstall', 'enabled', 'disabled']);
        foreach (glob($this->getAddonsPath() . '*/*.php') as $addonsFile) {
            $info = pathinfo($addonsFile);
            $name = pathinfo($info['dirname'], PATHINFO_FILENAME);
            if (strtolower($info['filename']) === 'plugin') {
                // 读取出所有插件的Ini配置
                $ini= $info['dirname'] .DS. 'service.ini';
                if (!is_file($ini)) {
                    continue;
                }
                $addonIni = parse_ini_file($ini, true, INI_SCANNER_TYPED) ?: [];
                if(!$addonIni['status']) continue;
                if(!$addonIni['install']) continue;
                $this->addonsIniArray[$addonIni['name']] = $addonIni;

                // 循环将钩子方法写入配置中
                $methods = get_class_methods('\\addons\\' . $name . '\\' . $info['filename']);
                $hooks = array_diff($methods, $base);
                foreach ($hooks as $hook) {
                    if (empty($config['hooks'][$hook])) {
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

        addons_vendor_autoload($this->addonsIniArray);
        Config::set($config, 'addons');
    }

    /**
     * 加载语言
     */
    private function loadLang(): void
    {
        Lang::load([$this->app->getRootPath() . '/vendor/topwait/wait-addons/src/lang/zh-cn.php']);
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
            foreach ($hooks['AddonsInit'] as $v) {
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
            foreach (scandir($moduleDir) as $mDir) {
                if (in_array($mDir, ['.', '..'])) {
                    continue;
                }

                if(is_file($this->addonsPath . $addonName . DS . $mDir)) {
                    continue;
                }

                $addonRouteFile = $this->addonsPath . $addonName . DS . $mDir . DS . 'route.php';
                $addonsRouteDir = $this->addonsPath . $addonName . DS . $mDir . DS . 'route' . DS;
                if (file_exists($addonsRouteDir) && is_dir($addonsRouteDir)) {
                    $files = glob($addonsRouteDir . '*.php');
                    foreach ($files as $file) {
                        if (file_exists($file)) {
                            $this->loadRoutesFrom($file);
                        }
                    }
                } elseif (file_exists($addonRouteFile) && is_file($addonRouteFile)) {
                    $this->loadRoutesFrom($addonRouteFile);
                }
            }
        }
    }

    /**
     * 加载配置
     */
    private function loadApp(): void
    {
        $app   = app();
        $rules = explode('/', ltrim($app->request->url(), '/'));
        $addon  = $rules[0] ?? '';
        $module = $rules[1] ?? '';
        if ($addon !== 'addons') {
            if (($rules[1]??'') === 'addons') {
                $addon  = $rules[1] ?? '';
                $module = $rules[2] ?? '';
            }
        }

        if ($addon !== 'addons' || !$module) {
            $routes = (array) Config::get('addons.route', []);
            $domain = explode('.', httpDomain())[0];
            foreach ($routes as $key => $val) {
                if (!$val) { continue; }
                if (is_array($val) && trim($val['domain']) === $domain) {
                    $addon  = trim($val['addons']);
                    $module = trim($val['module']);
                }
            }
        }

        // 加载插件应用级配置
        if (is_dir($this->addonsPath)) {
            foreach (scandir($this->addonsPath) as $name) {
                if (in_array($name, ['.', '..', 'public', 'view', 'middleware'])) {
                    continue;
                }

                $appConfigs = ['common.php', 'middleware.php', 'provider.php', 'event.php'];
                if (in_array($name, $appConfigs)) {
                    $appCommonPath = $this->addonsPath . DS . 'common.php';
                    if (is_file($appCommonPath)) {
                        include_once $appCommonPath;
                    }

                    $appMiddlewarePath = $this->addonsPath . DS . 'middleware.php';
                    if (is_file($appMiddlewarePath)) {
                        $app->middleware->import(include $appMiddlewarePath, 'route');
                    }

                    $appProviderPath = $this->addonsPath . DS . 'provider.php';
                    if (is_file($appProviderPath)) {
                        $app->bind(include $appProviderPath);
                    }

                    $appEventPath = $this->addonsPath . DS . 'event.php';
                    if (is_file($appEventPath)) {
                        $app->loadEvent(include $appEventPath);
                    }

                    $commands = [];
                    $addonsConfigDir = $this->addonsPath . DS . 'config' . DS;
                    if (is_dir($addonsConfigDir)) {
                        $files = [];
                        $files = array_merge($files, glob($addonsConfigDir . '*' . $app->getConfigExt()));
                        if ($files) {
                            foreach ($files as $file) {
                                if (file_exists($file)) {
                                    if (str_ends_with($file, 'console.php')) {
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

                    $addonsLangDir = $this->addonsPath . DS . 'lang' . DS;
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
        if (is_dir($this->addonsPath . $module)) {
            foreach (scandir($this->addonsPath . $module) as $modName) {
                if (in_array($modName, ['.', '..', 'public', 'view'])) {
                    continue;
                }

                $moduleConfigs = ['common.php', 'middleware.php', 'provider.php', 'event.php', 'config'];
                if (in_array($modName, $moduleConfigs)) {
                    $modCommonPath = $this->addonsPath . $module . DS . 'common.php';
                    if (is_file($modCommonPath)) {
                        include_once $modCommonPath;
                    }

                    $modMiddlewarePath = $this->addonsPath . $module . DS . 'middleware.php';
                    if (is_file($modMiddlewarePath)) {
                        $app->middleware->import(include $modMiddlewarePath, 'route');
                    }

                    $modProviderPath = $this->addonsPath . $module . DS . 'provider.php';
                    if (is_file($modProviderPath)) {
                        $app->bind(include $modProviderPath);
                    }

                    $modEventPath = $this->addonsPath . $module . DS . 'event.php';
                    if (is_file($modEventPath)) {
                        $app->loadEvent(include $modEventPath);
                    }

                    $commands = [];
                    $moduleConfigDir = $this->addonsPath . $module . DS . 'config' . DS;
                    if (is_dir($moduleConfigDir)) {
                        $files = [];
                        $files = array_merge($files, glob($moduleConfigDir . '*' . $app->getConfigExt()));
                        if($files){
                            foreach ($files as $file) {
                                if (file_exists($file)) {
                                    if (!str_ends_with($file, 'console.php')) {
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

                    $addonsLangDir = $this->addonsPath . $module . DS . 'lang' . DS;
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
     * 拷贝目录
     *
     * @param string $source (原始目录路径)
     * @param string $target (目标目录路径)
     * @author zero
     */
    private static function copyDir(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }

        foreach (
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            ) as $item
        ) {
            if ($item->isDir()) {
                $sonDir = $target . $iterator->getSubPathName();
                if (!is_dir($sonDir)) {
                    mkdir($sonDir, 0755, true);
                }
            } else {
                $to = rtrim(rtrim($target, '\\'), '/');
                copy($item->getPathName(), $to . DS . $iterator->getSubPathName());
            }
        }
    }

    /**
     * 删除目录
     *
     * @param string $dir (目录路径)
     * @author zero
     */
    private static function deleteDir(string $dir): void
    {
        // 验证是否目录
        if(!is_dir($dir)) {
            return;
        }

        // 递归删除文件
        $dh=opendir($dir);
        while ($file=readdir($dh)) {
            if($file!="." && $file!="..") {
                $fullPath=$dir."/".$file;
                if(!is_dir($fullPath)) {
                    @unlink($fullPath);
                } else {
                    self::deleteDir($fullPath);
                }
            }
        }
        closedir($dh);
        @rmdir($dir);
    }

    /**
     * 移除空目录
     *
     * @param string $dir (目录路径)
     * @author zero
     */
    private static function removeEmptyDir(string $dir): void
    {
        try {
            $isDirEmpty = !(new FilesystemIterator($dir))->valid();
            if ($isDirEmpty) {
                @rmdir($dir);
                self::removeEmptyDir(dirname($dir));
            }
        } catch (UnexpectedValueException | Exception) {}
    }

    /**
     * 获设插件RC
     *
     * @param string $name   (插件名称)
     * @param array $changed (变动后数据)
     * @return array
     * @author zero
     */
    private static function addonrc(string $name, array $changed = []): array
    {
        $addonConfigFile = self::getAddonsDirs($name) . '.addonrc';

        $config = [];
        if (is_file($addonConfigFile)) {
            $config = (array) json_decode(file_get_contents($addonConfigFile), true);
        }

        $config = array_merge($config, $changed);
        if ($changed) {
            file_put_contents($addonConfigFile, json_encode($config, JSON_UNESCAPED_UNICODE));
        }

        return $config;
    }

    /**
     * 获取插件路径
     *
     * @return string
     * @author zero
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
     * 获取插件目录
     *
     * @param string $name
     * @return string
     * @author zero
     */
    public static function getAddonsDirs(string $name): string
    {
        return app()->getRootPath() . 'addons' . DS . $name . DS;
    }

    /**
     * 取插件全局文件
     *
     * @param string $name (插件名称)
     * @param bool $onlyConflict (是否只返回冲突文件)
     * @return array
     * @author zero
     */
    public static function getGlobalAddonsFiles(string $name, bool $onlyConflict = false): array
    {
        $list = [];
        $addonDir = app()->getRootPath() . 'addons' . DS . $name . DS;
        $assetDir = get_target_assets_dir($name);

        // 扫描插件目录是否有覆盖的文件
        foreach (['app', 'public'] as $dirName) {
            // 检测目录是否存在
            $addonPublicPath = $addonDir . $dirName . DS;
            if (!is_dir($addonPublicPath)) {
                continue;
            }

            // 检测不存在则创建
            if (!is_dir($assetDir)) {
                mkdir($assetDir, 0755, true);
            }

            // 匹配出所有的文件
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($addonPublicPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($files as $fileInfo) {
                if ($fileInfo->isFile()) {
                    // 获取出插件对应目录路径
                    $filePath = $fileInfo->getPathName();

                    // 处理插件基本的目录路径
                    $path = str_replace($addonDir, '', $filePath);

                    // 对插件静态文件特殊处理
                    if ($dirName === 'public') {
                        $path = str_replace(root_path(), '', $assetDir) . str_replace($addonDir . $dirName . DS, '', $filePath);
                    }

                    if ($onlyConflict) {
                        // 与插件原文件有冲突
                        $destPath = root_path() . $path;
                        if (is_file($destPath)) {
                            if (filesize($filePath) != filesize($destPath) || md5_file($filePath) != md5_file($destPath)) {
                                $list[] = $path;
                            }
                        }
                    } else {
                        // 与插件原文件无冲突
                        $list[] = $path;
                    }
                }
            }
        }

        return array_filter(array_unique($list));
    }


    /**
     * 安装插件应用
     *
     * @param string $name (名称)
     * @param false $isDelete (是否删除)
     * @author zero
     */
    public static function installAddonsApp(string $name, bool $isDelete = false): void
    {
        // 刷新插件配置缓存
        $files = self::getGlobalAddonsFiles($name);
        if ($files) {
            self::addonrc($name, ['files' => $files]);
        }

        // 复制应用到全局位
        foreach (['app', 'public'] as $dir) {
            $sourceDir = self::getAddonsDirs($name) . $dir;
            $targetDir = app()->getBasePath();

            if ($dir === 'public') {
                $targetDir = app()->getRootPath() . $dir . DS . 'static' . DS . 'addons' . DS . $name . DS;
            }

            if (is_dir($sourceDir)) {
                self::copyDir($sourceDir, $targetDir);
                if ($isDelete) {
                    self::deleteDir(self::getAddonsDirs($name) . $dir);
                }
            }
        }
    }

    /**
     * 卸载插件应用
     *
     * @param string $name (名称)
     * @author zero
     */
    public static function uninstallAddonsApp(string $name): void
    {
        $addonRc  = self::addonrc($name);
        $addonDir = self::getAddonsDirs($name);
        $filesArr = self::getGlobalAddonsFiles($name);
        $targetAssetsDir = get_target_assets_dir($name);

        // 把散布在全局的文件复制回插件目录
        if ($addonRc && isset($addonRc['files']) && is_array($addonRc['files'])) {
            foreach ($addonRc['files'] as $item) {
                // 避免不同服务器路径不一样
                $item = str_replace(['/', '\\'], DS, $item);
                $path = root_path() . $item;

                // 针对静态资源的特殊的处理
                if (stripos($item, str_replace(root_path(), '', $targetAssetsDir)) === 0) {
                    $baseAssert = str_replace(root_path(), '', $targetAssetsDir);
                    $item = 'public' . DS . str_replace($baseAssert, '', $item);
                }

                // 检查插件目录不存在则创建
                $itemBaseDir = dirname($addonDir . $item);
                if (!is_dir($itemBaseDir)) {
                    @mkdir($itemBaseDir, 0755, true);
                }

                // 检查如果是文件则移动位置
                if (is_file($path)) {
                    @copy($path, $addonDir.$item);
                }
            }
            $filesArr = $addonRc['files'];
        }

        // 移除插件的文件
        $dirs = [];
        foreach ($filesArr as $path) {
            $file = root_path() . $path;
            $dirs[] = dirname($file);
            @unlink($file);
        }

        // 移除插件空目录
        $dirs = array_filter(array_unique($dirs));
        foreach ($dirs as $path) {
            self::removeEmptyDir($path);
        }
    }
}
