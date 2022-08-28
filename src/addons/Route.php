<?php
declare(strict_types=1);

namespace think\addons;

use think\facade\Request;
use think\helper\Str;
use think\facade\Event;
use think\facade\Config;
use think\facade\View;
use think\exception\HttpException;

class Route
{
    /**
     * 插件路由请求
     *
     * @param string $module
     * @return mixed
     */
    public static function execute($module = 'index')
    {
        $app = app();
        $request    = $app->request;
        $addon      = $request->route('addon');
        $controller = $request->route('controller');
        $action     = $request->route('action');

        // 监听前置事件
        Event::trigger('addons_begin', $request);

        // 验证插件路由
        if (empty($addon) || empty($controller) || empty($action)) {
            throw new HttpException(500, lang('addon can not be empty'));
        }

        // 设置请求操作
        $request->addon = $addon;
        $request->setController("{$module}.{$controller}")->setAction($action);

        // 验证是否可用
        $info = get_addons_info($addon);
        if (!$info || !$info['status']) {
            $message = !$info ? [404, 'addon %s not found'] : [500, 'addon %s is disabled'];
            throw new HttpException($message[0], lang($message[1], [$addon]));
        }

        // 监听初始事件
        Event::trigger('addon_module_init', $request);
        $class = get_addons_class($addon, 'controller', $controller, $module);
        if (!$class) {
            throw new HttpException(404, lang('addon controller %s not found', [Str::studly($module.DS.$controller)]));
        }

        // 重写视图路径
        $viewConfig = Config::get('view');
        $appViewPath = $app->addons->getAddonsPath() . $addon . DS . $module . DS . 'view' . DS;
        $pubViewPath = $app->addons->getAddonsPath() . $addon . DS . 'view'. DS;
        $viewConfig['view_path'] = is_dir($appViewPath) ? $appViewPath : $pubViewPath;
        Config::set($viewConfig, 'view');
        View::engine('Think')->config($viewConfig);
        if (is_dir($appViewPath) && $module) {
            $viewController = $request->controller();
            $viewController = substr_replace($viewController, '', 0, strlen($module)+1);
            Request::setController($viewController);
        }

        // 生成控制器对象
        $vars = [];
        $instance = new $class($app);
        if (is_callable([$instance, $action])) {
            $call = [$instance, $action];
        } elseif (is_callable([$instance, '_empty'])) {
            $call = [$instance, '_empty'];
            $vars = [$action];
        } else {
            throw new HttpException(404, lang('addon action %s not found', [get_class($instance).'->'.$action.'()']));
        }

        // 监听后置事件
        Event::trigger('addons_action_begin', $call);

        // 返回调用函数
        return call_user_func_array($call, $vars);
    }
}
