<?php

namespace app\model;

use sower\Model;

class UserToken extends Model
{
    protected $connection = 'user'; 
    // 表名
    protected $name = 'user_token';
}
