<?php

namespace app;
use app\Auth;
use sowers\Config;
use sower\exception\HttpResponseException;
use sower\exception\ValidateException;
use sowers\Event;
use sowers\Lang;
use sower\App;
use sowers\Request;
use sower\Response;
use sower\Validate;
//跨域设置
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type,token,uid,Host,Cookie,Authorization, Accept, authKey, sessionId");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods:GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header('Access-Control-Allow-Headers:x-requested-with,content-type,token,uid,cookie,Cookie,Authorization');
    //浏览器页面ajax跨域请求会请求2次，第一次会发送OPTIONS预请求,不进行处理，直接exit返回，但因为下次发送真正的请求头部有带token，所以这里设置允许下次请求头带token否者下次请求无法成功
    exit("OPTIONS");
}
class Api
{

    /**
     * @var app App 实例
     */
    protected $app;
    /**
     * @var Request Request 实例
     */
    protected $request;

    /**
     * @var bool 验证失败是否抛出异常
     */
    protected $failException = false;

    /**
     * @var bool 是否批量验证
     */
    protected $batchValidate = false;

    /**
     * @var array 前置操作方法列表
     */
    protected $beforeActionList = [];

    /**
     * 无需登录的方法
     * @var array
     */
    protected $NoLogin = [];

    /**
     * 无需鉴权的方法,但需要登录
     * @var array
     */


    /**
     * 权限Auth
     * @var Auth
     */
    protected $auth = null;

    /**
     * 默认响应输出类型,支持json/xml
     * @var string
     */
    protected $responseType = 'json';

    /**
     * 构造方法
     * @access public
     * @param app $app App 对象
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;
        // 控制器初始化
        $this->initialize();
        // 前置操作方法
        if ($this->beforeActionList) {
            foreach ($this->beforeActionList as $method => $options) {
                is_numeric($method) ?
                    $this->beforeAction($options) :
                    $this->beforeAction($method, $options);
            }
        }
    }

    /**
     * 初始化操作
     * @access protected
     */
    protected function initialize()
    {
        //移除HTML标签
        $this->request->filter('trim,strip_tags,htmlspecialchars');

        $this->auth = Auth::instance();

        $modulename = $this->request->app();
        $controllername = strtolower($this->request->controller());
        $actionname = strtolower($this->request->action());
        // token
        //$token = $this->request->server('HTTP_TOKEN', $this->request->request('token', \sowers\Cookie::get('token')));
        $http_type = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || (isset($_SERVER['HTTP_TOKEN']) && $_SERVER['HTTP_TOKEN'] == 'https')) ? 'https://' : 'http://';
	   	  $token = $this->request->server('HTTP_TOKEN', $this->request->request('token', $http_type));
	    	$path = str_replace('.', '/', $controllername) . '/' . $actionname;
        // 设置当前请求的URI
        $this->auth->setRequestUri($path);
        if (!$this->auth->match($this->NoLogin)) {
            //初始化
            $this->auth->init($token);
            //检测登录
            if (!$this->auth->isLogin()) {
                $this->error(SoLan('Please login first'), null, 100);
            }
        } else {
            // 如果有传递token才验证是否登录状态
            if ($token) {
                $this->auth->init($token);
            }
        }

        $upload = \sums\Config::upload();

        // 上传信息配置后
        Event::listen("upload_config_init", $upload);

        Config::set(['upload'=>array_merge(Config::get('upload'), $upload)],'config');

        // 加载当前控制器语言包
        $this->loadlang($controllername);
    }

    /**
     * 加载语言文件
     * @param string $name
     */
    protected function loadlang($name)
    {
        $langset = cookie('Sower_var')?cookie('Sower_var'):Lang::getLangSet();
        Lang::load(APP_PATH . $this->request->app() . '/lang/' . $langset . '/' . str_replace('.', '/', $name) . '.php');
    }

    /**
     * 操作成功返回的数据
     * @param string $msg    提示信息
     * @param mixed  $data   要返回的数据
     * @param int    $code   错误码，默认为1
     * @param string $type   输出类型
     * @param array  $header 发送的 Header 信息
     */
    protected function success($msg = '', $data = null, $code = 0, $type = null, array $header = [])
    {
        $this->result($msg, $data, $code, $type, $header);
    }

    /**
     * 操作失败返回的数据
     * @param string $msg    提示信息
     * @param mixed  $data   要返回的数据
     * @param int    $code   错误码，默认为0
     * @param string $type   输出类型
     * @param array  $header 发送的 Header 信息
     */
    protected function error($msg = '', $data = null, $code = 1, $type = null, array $header = [])
    {
        $this->result($msg, $data, $code, $type, $header);
    }

    /**
     * 返回封装后的 API 数据到客户端
     * @access protected
     * @param mixed  $msg    提示信息
     * @param mixed  $data   要返回的数据
     * @param int    $code   错误码，默认为0
     * @param string $type   输出类型，支持json/xml/jsonp
     * @param array  $header 发送的 Header 信息
     * @return void
     * @throws HttpResponseException
     */
    protected function result($msg, $data = null, $code = 0, $type = null, array $header = [])
    {
        $result = [
            'code' => $code,
            'msg'  => $msg,
            'time' => Request::instance()->server('REQUEST_TIME'),
            'data' => $data,
        ];
        // 如果未设置类型则自动判断
        $type = $type ? $type : ($this->request->param(config('routes.var_jsonp_handler')) ? 'json' : $this->responseType);

        if (isset($header['statuscode'])) {
            $code = $header['statuscode'];
            unset($header['statuscode']);
        } else {
            //未设置状态码,根据code值判断
            $code = $code >= 1000 || $code < 200 ? 200 : $code;
        }
        $response = Response::create($result, $type, $code)->header($header);
        throw new HttpResponseException($response);
    }

    /**
     * 前置操作
     * @access protected
     * @param  string $method  前置操作方法名
     * @param  array  $options 调用参数 ['only'=>[...]] 或者 ['except'=>[...]]
     * @return void
     */
    protected function beforeAction($method, $options = [])
    {
        if (isset($options['only'])) {
            if (is_string($options['only'])) {
                $options['only'] = explode(',', $options['only']);
            }

            if (!in_array($this->request->action(), $options['only'])) {
                return;
            }
        } elseif (isset($options['except'])) {
            if (is_string($options['except'])) {
                $options['except'] = explode(',', $options['except']);
            }

            if (in_array($this->request->action(), $options['except'])) {
                return;
            }
        }

        call_user_func([$this, $method]);
    }

    /**
     * 设置验证失败后是否抛出异常
     * @access protected
     * @param bool $fail 是否抛出异常
     * @return $this
     */
    protected function validateFailException($fail = true)
    {
        $this->failException = $fail;

        return $this;
    }


    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, $validate, array $message = [], bool $batch = false)
    {
        try {
            if (is_array($validate)) {
                $v = new Validate();
                $v->rule($validate);
            } else {
                if (strpos($validate, '.')) {
                    // 支持场景
                    list($validate, $scene) = explode('.', $validate);
                }
                $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
                $v     = new $class();
                if (!empty($scene)) {
                    $v->scene($scene);
                }
            }

            // 是否批量验证
            if ($batch || $this->batchValidate) {
                $v->batch(true);
            }
            $result = $v->failException(true)->check($data);
        } catch (\sower\exception\ValidateException $e) {
            $error = $e->getError();
            foreach ($message as $key => $value) {
                $error = str_replace($key,$message[$key],$error);
            }
            // 验证失败 输出错误信息
            $this->error($error);
        }
        return true;
    }
}
