<?php
namespace Vda\DependencyInjection;

class DI
{
    private static $container;

    public static function init(IContainer $container)
    {
        self::$container = $container;
    }

    public static function get($beanName)
    {
        return self::$container->get($beanName);
    }

    public static function hasBean($beanName)
    {
        return self::$container->hasBean($beanName);
    }

    public static function test(array $beanNames = null)
    {
        self::$container->test($beanNames);
    }
}
