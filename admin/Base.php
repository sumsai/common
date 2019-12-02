<?php

namespace admin;
use sowers\Config;
use sowers\Lang;
use sowers\Session;
use sums\Tree;
use admin\Auth;
use app\Controller;
//后台控制器基类
class Base extends Controller
{

    //无需登录的方法
    protected $NoLogin = [];

    //无需鉴权的方法
    protected $NoAuthurl = [];

    //权限控制类
    protected $auth = null;

    //模型对象
    protected $model = null;

    //快速搜索时执行查找的字段
    protected $searchFields = 'id';

    //是否是关联查询
    protected $relationSearch = false;

    //是否开启数据限制
    protected $dataLimit = false;

    //数据限制字段
    protected $dataLimitField = 'admin_id';

    //数据限制开启时自动填充限制字段值
    protected $dataLimitFieldAutoFill = true;

    //是否开启Validate验证
    protected $modelValidate = false;

    //是否开启模型场景验证
    protected $modelSceneValidate = false;

    //Multi方法可批量修改的字段
    protected $multiFields = 'status';

    //Selectpage可显示的字段
    protected $selectpageFields = '*';

    //前台提交过来,需要排除的字段数据
    protected $excludeFields = "";

    /**
     * 导入文件首行类型
     * 支持comment/name
     * 表示注释或字段名
     */
    protected $importHeadType = 'comment';

    //模板文件
    protected $pathinfo = '';

    /**
     * 引入后台控制器的traits
     */
    use \admin\traits\Backend;

    public function initialize()
    {
        parent::initialize();
        
        $path =  DIRECTORY_SEPARATOR . $this->modulename . DIRECTORY_SEPARATOR . str_replace($this->modulename . '.', '/', $this->controllername) . '/' . $this->actionname;
        $this->auth = Auth::instance();
         $this->path =  $path;
        // 设置当前请求的URI
        $this->auth->setRequestUri($path);

        // 定义是否Addtabs请求
        !defined('IS_ADDTABS') && define('IS_ADDTABS', input("addtabs") ? true : false);
        // 定义是否Dialog请求
        !defined('IS_DIALOG') && define('IS_DIALOG', input("dialog") ? true : false);
        // 定义是否AJAX请求
        !defined('IS_AJAX') && define('IS_AJAX', $this->request->isAjax());

        // 检测是否需要验证登录
        if (!$this->auth->match($this->NoLogin)) {
            //检测是否登录
            if (!$this->auth->isLogin()) {
                event('admin_nologin', $this);
                $url = Session::get('referer');
                $url = $url ? $url : $this->request->url();
                if ($url == '/') {
                    $this->redirect('index/login', [], 302, ['referer' => $url]);
                    exit;
                }
                $this->error(SoLan('Please login first'), url('index/login', ['url' => $url]));
            }
            // 判断是否需要验证权限
            if (!$this->auth->match($this->NoAuthurl)) {
                // 判断控制器和方法判断是否有对应权限
                if (!$this->auth->check($path)) {
                    event('admin_nopermission', $this);
                    $this->error(SoLan('You have no permission'), '');
                }
            }
        }

        // 非选项卡时重定向
        if (!$this->request->isPost() && !IS_AJAX && !IS_ADDTABS && !IS_DIALOG && input("ref") == 'addtabs') {
            $url = preg_replace_callback("/([\?|&]+)ref=addtabs(&?)/i", function ($matches) {
                return $matches[2] == '&' ? $matches[1] : '';
            }, $this->request->url());
            if (config('routes.url_domain_deploy')) {
                if (stripos($url, $this->request->server('SCRIPT_NAME')) === 0) {
                    $url = substr($url, strlen($this->request->server('SCRIPT_NAME')));
                }
                $url = url($url, '', false);
            }
            $this->redirect('index/index', [], 302, ['referer' => $url]);
        }

        // 设置面包屑导航数据
        $breadcrumb = $this->auth->getBreadCrumb($path);
        array_pop($breadcrumb);
        $this->view->breadcrumb = $breadcrumb;

        // 语言检测
        $langset = cookie('Sower_var')?cookie('Sower_var'):Lang::getLangSet();

        $site = Config::get("site");

        $upload = \sums\Config::upload();
        // 上传信息配置后
        event("upload_config_init", $upload);
        // 配置信息
        $config = [
            'site'           => array_intersect_key($site, array_flip(['name', 'indexurl', 'cdnurl', 'version', 'timezone', 'languages'])),
            'upload'         => $upload,
            'modulename'     => $this->modulename,
            'controllername' => $this->controllername,
            'actionname'     => $this->actionname,
            'jsname'         => 'controller/'. $this->modulename .'/' . str_replace('.', '/', $this->controllername),
            'moduleurl'      => rtrim(url("/{$this->modulename}", [], false), '/'),
            'language'       => strip_tags($langset),
            'Suansu'      => Config::get('Suansu'),
            'referer'        => Session::get("referer")
        ];
        $config = array_merge($config, Config::get("template.custom"));

        Config::set(['upload'=>array_merge(Config::get('upload'), $upload)],'config');

        // 配置信息后
        event("config_init", $config);
        //加载当前控制器语言包
        $this->loadlang($this->controllername);
        //渲染站点配置
        $this->view->assign('site', $site);
        //渲染配置信息
        $this->view->assign('config', $config);
        //渲染权限对象
        $this->view->assign('auth', $this->auth);
        //渲染管理员对象
        $this->view->assign('admin', Session::get('admin'));
    }
    /**
     * 生成查询所需要的条件,排序方式
     * @param mixed   $searchfields   快速查询的字段
     * @param boolean $relationSearch 是否关联查询
     * @return array
     */	
	protected function NewQuery($searchfields = null, $relationSearch = null)
    {
        $searchfields = is_null($searchfields) ? $this->searchFields : $searchfields;
        $relationSearch = is_null($relationSearch) ? $this->relationSearch : $relationSearch;
        $search = $this->request->get("search", '');
        $filter = $this->request->get("find", '');
        $op = $this->request->get("op", '', 'trim');
        $sorts = $this->request->get("sort", "id");
        $mode = $this->request->get("order", "DESC");
        $rows = $this->request->get("rows", 0);
        $number = $this->request->get("limit", 0);
        $filter = (array)json_decode($filter, true);
        $op = (array)json_decode($op, true);
        $filter = $filter ? $filter : [];
        $where = [];
        $where[] = ['id','>',0];
        $tableName = '';
        if ($relationSearch) {
            if (!empty($this->model)) {
                $name = app()->parseName(basename(str_replace('\\', '/', get_class($this->model))));
                $tableName = $name . '.';
            }
            $sortArr = explode(',', $sorts);
            foreach ($sortArr as $index => & $item) {
                $item = stripos($item, ".") === false ? $tableName . trim($item) : $item;
            }
            unset($item);
            $sorts = implode(',', $sortArr);
        }
        $adminIds = $this->getDataLimitAdminIds();
        if (is_array($adminIds)) {
            $where[] = [$tableName . $this->dataLimitField, 'in', $adminIds];
        }
        if ($search) {
            $searcharr = is_array($searchfields) ? $searchfields : explode(',', $searchfields);
            foreach ($searcharr as $k => &$v) {
                $v = stripos($v, ".") === false ? $tableName . $v : $v;
            }
            unset($v);
            $where[] = [implode("|", $searcharr), "LIKE", "%{$search}%"];
        }
        foreach ($filter as $k => $v) {
            $sym = isset($op[$k]) ? $op[$k] : '=';
            if (stripos($k, ".") === false) {
                $k = $tableName . $k;
            }
            $v = !is_array($v) ? trim($v) : $v;
            $sym = strtoupper(isset($op[$k]) ? $op[$k] : $sym);
            switch ($sym) {
                case '=':
                case '<>':
                    $where[] = [$k, $sym, (string)$v];
                    break;
                case 'LIKE':
                case 'NOT LIKE':
                case 'LIKE %...%':
                case 'NOT LIKE %...%':
                    $where[] = [$k, trim(str_replace('%...%', '', $sym)), "%{$v}%"];
                    break;
                case '>':
                case '>=':
                case '<':
                case '<=':
                    $where[] = [$k, $sym, intval($v)];
                    break;
                case 'FINDIN':
                case 'FINDINSET':
                case 'FIND_IN_SET':
                    $where[] = "FIND_IN_SET('{$v}', " . ($relationSearch ? $k : '`' . str_replace('.', '`.`', $k) . '`') . ")";
                    break;
                case 'IN':
                case 'IN(...)':
                case 'NOT IN':
                case 'NOT IN(...)':
                    $where[] = [$k, str_replace('(...)', '', $sym), is_array($v) ? $v : explode(',', $v)];
                    break;
                case 'BETWEEN':
                case 'NOT BETWEEN':
                    $arr = array_slice(explode(',', $v), 0, 2);
                    if (stripos($v, ',') === false || !array_filter($arr)) {
                        continue 2;
                    }
                    //当出现一边为空时改变操作符
                    if ($arr[0] === '') {
                        $sym = $sym == 'BETWEEN' ? '<=' : '>';
                        $arr = $arr[1];
                    } elseif ($arr[1] === '') {
                        $sym = $sym == 'BETWEEN' ? '>=' : '<';
                        $arr = $arr[0];
                    }
                    $where[] = [$k, $sym, $arr];
                    break;
                case 'RANGE':
                case 'NOT RANGE':
                    $v = str_replace(' - ', ',', $v);
                    $arr = array_slice(explode(',', $v), 0, 2);
                    if (stripos($v, ',') === false || !array_filter($arr)) {
                        continue 2;
                    }
                    //当出现一边为空时改变操作符
                    if ($arr[0] === '') {
                        $sym = $sym == 'RANGE' ? '<=' : '>';
                        $arr = $arr[1];
                    } elseif ($arr[1] === '') {
                        $sym = $sym == 'RANGE' ? '>=' : '<';
                        $arr = $arr[0];
                    }
                    $where[] = [$k, str_replace('RANGE', 'BETWEEN', $sym) . ' time', $arr];
                    break;
                case 'LIKE':
                case 'LIKE %...%':
                    $where[] = [$k, 'LIKE', "%{$v}%"];
                    break;
                case 'NULL':
                case 'IS NULL':
                case 'NOT NULL':
                case 'IS NOT NULL':
                    $where[] = [$k, strtolower(str_replace('IS ', '', $sym))];
                    break;
                default:
                    break;
            }
        }
        $where = function ($query) use ($where) {
            foreach ($where as $k => $v) {
                if (is_array($v)) {
                    call_user_func_array([$query, 'where'], $v);
                } else {
                    $query->where($v);
                }
            }
        };
        return [$where, $sorts, $mode, $rows, $number,$filter];
       
    }
    /**
     * 获取数据限制的管理员ID
     * 禁用数据限制时返回的是null
     * @return mixed
     */
    protected function getDataLimitAdminIds()
    {
        if (!$this->dataLimit) {
            return null;
        }
        if ($this->auth->isSuperAdmin()) {
            return null;
        }
        $adminIds = [];
        if (in_array($this->dataLimit, ['auth', 'personal'])) {
            $adminIds = $this->dataLimit == 'auth' ? $this->auth->getChildrenAdminIds(true) : [$this->auth->id];
        }
        return $adminIds;
    }

}
