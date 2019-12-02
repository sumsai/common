<?php

namespace app\model;

use sower\Model;

//用户模型

class User extends Model
{
    protected $connection = 'user'; 
    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

}
