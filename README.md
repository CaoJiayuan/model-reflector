# Usage


```php
 
<?php
namespace App\Lib;
use Nerio\ModelReflector\ModelReflector;
class User extends ModelReflector
{
    public $name;
    public $age;

    /**
     * @var UserInfo
     */
    public $info;
}
```

```php
<?php 

namespace App\Lib;
use Nerio\ModelReflector\ModelReflector;

/**
 * @author caojiayuan
 */
class UserInfo extends ModelReflector
{
    public $desc;
    public $avatar;
}
```
    

```php
<?php
$map = \App\Lib\User::make([
   'name' => 'Tom',
   'age'  => 19,
   'info' => [
       'desc' => "I'm Tom",
       'avatar' => 'http://xxxxx.jpg'
   ]
]);

echo $map->info->avatar;
```