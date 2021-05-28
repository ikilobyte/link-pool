<?php
/**
 * Created by PhpStorm.
 * User: Sunny
 * Date: 2021/5/28
 * Time: 11:31 上午
 */

namespace LinkPool;

use Swoole\Coroutine\Channel;
use Closure;
use Exception;
use Throwable;



/**
 * Class ConnectionPool
 * @package LinkPool
 */
abstract class ConnectionPool
{
    /**
     * 默认数量（每个进程的）
     */
    protected const DEFAULT_SIZE = 35;

    /**
     * @var int 最小数量（至少保持这些数量在连接池中）
     */
    protected $minSize = 15;

    /**
     * @var int 最大数量
     */
    protected $maxSize = self::DEFAULT_SIZE;


    /**
     * @var int 连接池的数量
     */
    protected $count = 0;


    /**
     * @var int 当前数量
     */
    protected $current = 0;


    /**
     * @var array 连接池
     */
    protected $pools = [];


    /**
     * @var Closure
     */
    protected $construct;


    /**
     * @var Channel
     */
    protected $channel;


    /**
     * MysqlPool constructor.
     * @param Closure $construct
     * @param int $size
     */
    public function __construct(Closure $construct,$size = self::DEFAULT_SIZE)
    {
        $this -> construct  = $construct;
        $this -> maxSize    = $size;
        $this -> channel    = new Channel($this -> maxSize + 1);
    }



    /**
     * @return $this
     */
    public function init()
    {
        // 一定要判断总数
        while ($this -> count < $this -> minSize) {
            $this -> push($this -> make());
        }

        return $this;
    }


    /**
     * @return int
     * @throws Exception
     * 获取长度
     */
    public function length() : int
    {
        if(empty($this -> channel)) {
            throw new Exception('channel fail');
        }

        return $this -> channel -> length();
    }


    /**
     * @param int $timeout
     * @return mixed
     * @throws Exception
     * 获取一个连接
     */
    public function get($timeout = 3)
    {
        if(empty($this -> channel)) {
            throw new Exception('channel fail');
        }


        if( $this -> channel -> isEmpty() ) {
            // 当前创建的数量，未超过最大限制数量，则继续创建
            if($this -> count < $this -> maxSize) {
                $this -> push($this -> make());
            }
        }

        // 取出一个
        $item = $this -> channel -> pop($timeout);

        return $item['resource'] ?? null;
    }


    /**
     * @param $resource
     * @return mixed
     * @throws Exception
     * 归还一个连接
     */
    public function put($resource)
    {
        try{

            if(empty($this -> channel)) {
                throw new Exception('put fail');
            }


            $hash   = spl_object_hash($resource);
            $old    = $this -> pools[$hash] ?? [];
            $item   = array_merge($old,[
                'resource'  => $resource,
                'use_time'  => microtime(true),
                'make_time' => $old['make_time'] ?? 0,
            ]);

            $this -> channel -> push($item);
            $this -> pools[$hash] = $item;

        }catch(Throwable $throwable){
            $this -> count -= 1;
        }

        return $this -> channel -> length();
    }



    /**
     * @param array $item
     * @param bool $increment
     */
    protected function push(array $item,$increment = true)
    {

        try {

            $this -> channel -> push($item);
            $this -> pools[$item['hash']]   = $item;

        } catch(Throwable $e) {
            if($increment) {
                $this -> count -= 1;
            }
        }
    }


    /**
     * @return array
     * 创建一个连接
     */
    abstract protected function make() : array;


    /**
     * @param $resource
     * @return bool
     * 是否连接
     */
    abstract protected function connected($resource) : bool;


    /**
     * @param int $second
     * @return mixed
     */
    abstract public function handleSpare(int $second = 2);
}