<?php

namespace app;

use app\Auth;
use sowers\Config;
use app\Controller;
use sowers\Lang;

/**
 * 前台控制器基类
 */
class Frontend extends Controller
{

    /**
     * 无需登录的方法,同时也就不需要鉴权了
     * @var array
     */
    protected $noNeedLogin = [];

    /**
     * 无需鉴权的方法,但需要登录
     * @var array
     */
    protected $noNeedRight = [];

    /**
     * 权限Auth
     * @var Auth
     */
    protected $auth = null;

    public function initialize()
    {
        parent::initialize();
        //移除HTML标签
        $this->request->filter('trim,strip_tags,htmlspecialchars');

        $this->auth = Auth::instance();
        // token
        $token = $this->request->server('HTTP_TOKEN', $this->request->request('token', \sowers\Cookie::get('token')));

        $path = str_replace('.', '/', $this->controllername) . '/' . $this->actionname;
        // 设置当前请求的URI
        $this->auth->setRequestUri($path);
        // 检测是否需要验证登录
        if (!$this->auth->match($this->noNeedLogin)) {
            //初始化
            $this->auth->init($token);
            //检测是否登录
            if (!$this->auth->isLogin()) {
                $this->error(__('Please login first'), 'index/user/login');
            }
        } else {
            // 如果有传递token才验证是否登录状态
            if ($token) {
                $this->auth->init($token);
            }
        }
        $this->view->assign('user', $this->auth->getUser());

        // 语言检测
        $langset = cookie('Sower_var')?cookie('Sower_var'):Lang::getLangSet();

        $site = Config::get("site");

        $upload = \app\common\model\Config::upload();

        // 上传信息配置后
        event("upload_config_init", $upload);

        // 配置信息
        $config = [
            'site'           => array_intersect_key($site, array_flip(['name', 'cdnurl', 'version', 'timezone', 'languages'])),
            'upload'         => $upload,
            'modulename'     => $this->modulename,
            'controllername' => $this->controllername,
            'actionname'     => $this->actionname,
            'jsname'         => 'frontend/' . str_replace('.', '/', $this->controllername),
            'moduleurl'      => rtrim(url("/{$this->modulename}",[], false), '/'),
            'language'       => strip_tags($langset)
        ];



        // 加载当前控制器语言包
        $this->loadlang($this->controllername);

        $this->view->assign('site', $site);
    
    }
}
