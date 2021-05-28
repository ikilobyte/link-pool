<?php
/**
 * Created by PhpStorm.
 * User: Sunny
 * Date: 2021/5/28
 * Time: 11:36 上午
 */

namespace LinkPool\Database;
use LinkPool\ConnectionPool;
use Swoole\Timer;
use PDO;
use Exception;
use Throwable;
use PDOException;

/**
 * Class PdoPool
 * @method PDO get($timeout = 3)
 * @method mixed put($resource)
 * @method int length()
 * @package LinkPool\Database
 */
class PdoPool extends ConnectionPool
{

    /**
     * @return array
     * @throws Exception
     */
    protected function make() : array
    {
        // 为了防止并发情况，先加上1
        // 在同一进程内，并发对变量赋值，其实是操作内存
        // 在php底层是原子操作，所以应该先加上1
        // 不能在make成功后在增加
        // 这样的话，并发时，读取到的count会出现相同的数值，从而造成超出创建数量
        $this -> count += 1;

        try {

            $pdo = call_user_func($this -> construct);

            if(!($pdo instanceof PDO)) {
                throw new Exception('make pdo fail');
            }

            // 获取这个进程的ID
            $threadId   = $this -> getThreadID($pdo);

            // 获取这个进程的ip，port
            $threadInfo = $this -> getThreadInfo($pdo, $threadId);
        } catch (Throwable $exception) {

            $this -> count -= 1;
            throw new Exception($exception -> getMessage());
        }

        return [
            'resource'      => $pdo,
            'use_time'      => 0,
            'make_time'     => microtime(true),
            'pid'           => getmypid(),
            'hash'          => spl_object_hash($pdo),
            'thread_id'     => $threadId,
            'thread_info'   => $threadInfo,
            'kick'          => false,
        ];
    }


    /**
     * @param PDO $pdo
     * @return int
     * 获取当前线程ID
     */
    protected function getThreadID(PDO $pdo) : int
    {
        $stmt = $pdo -> prepare('select connection_id() as thread_id');
        $stmt -> execute();
        $result = $stmt -> fetch();
        return $result -> thread_id;
    }



    /**
     * @param PDO $pdo
     * @param int $threadId
     * @return array
     */
    protected function getThreadInfo(PDO $pdo,int $threadId) : array
    {
        $stmt = $pdo -> prepare('show full processlist');
        $stmt -> execute();
        $result = $stmt -> fetchAll();

        $info   = [];
        foreach ($result as $key => $item)
        {
            if($item -> Id === $threadId) {

                list($ip,$port) = explode(':',$item -> Host);
                $info = [
                    'ip'        => $ip,
                    'port'      => $port,
                    'crc32'     => crc32($item -> Host),       // 就这样保存
                ];
                break;
            }
        }

        return $info;
    }


    /**
     * @param $resource
     * @return bool
     * 是否
     */
    protected function connected($resource): bool
    {
        try{
            $resource -> getAttribute(PDO::ATTR_SERVER_INFO);
        } catch (PDOException $e) {
            return false;
        }

        return true;
    }



    /**
     * @param int $second
     * @return mixed|void
     * 检测连接是否有效
     */
    public function handleSpare(int $second = 60)
    {
        Timer::tick($second * 1000,function(){

            $kickList   = [];
            $activeList = [];

            $pdo        = call_user_func($this -> construct);
            $result     = $pdo -> select('show full processlist');
            $combine    = array_combine(
                array_column($result,'Id'),
                array_column($result,'Host')
            );

            // 全部取出
            while ( !$this -> channel -> isEmpty() ) {

                // 这里取出来了，超时无法取出，返回值为false
                $item   = $this -> channel -> pop(0.001);
                if(empty($item)) {
                    continue;
                }

                $hash   = $item['hash'];
                $host   = $combine[$item['thread_id']] ?? '';
                $kick   = false;

                if(empty($host)) {
                    $kick = true;
                } else {
                    // 有这个进程ID，但ip和端口不一致，也需要踢出
                    if(crc32($host) !== $item['thread_info']['crc32']) {
                        $kick = true;
                    }
                }

                if(!$kick) {
                    if($item['make_time'] <= 0) {
                        $kick = true;
                    } else {

                        // 使用过
                        if($item['use_time'] >= 1) {
                            if(microtime(true) - $item['use_time'] > 60 * 5) {
                                $kick = true;
                            }
                        } else {
                            // 超过10分钟的空闲，从未使用
                            if(microtime(true) - $item['make_time'] > 60 * 10) {
                                $kick = true;
                            }
                        }
                    }
                }
                $this -> pools[$hash]['kick'] = $kick;

                // 需要踢出的
                if($kick) {
                    $kickList[]     = $item;
                } else {
                    $activeList[]   = $item;
                }
            }


            // 重新进入连接池
            foreach ($activeList as $key => $val)
            {
                $this -> channel -> push($val);
            }


            // 删除老连接
            foreach ($kickList as $key => $val)
            {
                $this -> count -= 1;
                unset($this -> pools[$val['hash']]);
            }


            // 每次检测都判断一下是否小于了最小连接
            $this -> init();


            // 关闭连接
            $pdo = null;
        });
    }
}